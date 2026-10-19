import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
const source=readFileSync(new URL('../assets/connect.js',import.meta.url),'utf8');
const settle=()=>new Promise(setImmediate);
function browser({pending=false,reconnect=false,popup=true,replies=[]}={}){
 const status={textContent:''},button={disabled:false},link={hidden:true,href:''},phrase={textContent:''},timers=[],calls=[],opened=[],events={};let reloads=0,now=0;
 const form={querySelector:q=>q.startsWith('button')?button:{value:reconnect?'agent_reconnect':'agent_enroll'},addEventListener:(name,fn)=>events[name]=fn};
 const container={dataset:{pending:pending?'1':'0'},closest:()=>form,querySelector:q=>q==='.sunrise-connect-status'?status:q==='[data-sunrise-approval]'?link:phrase};
 const config={nonce:'fixture',control:'https://control.example',enroll:'/connect',reconnect:'/reconnect',sync:'/sync'};
 const state={URL,Set,Promise,Date:{now:()=>now},setTimeout:(fn,ms)=>timers.push(()=>{now+=ms;fn();}),location:{reload:()=>reloads++},document:{querySelectorAll:()=>[container]},window:{sunriseConnect:config,open:()=>popup?{location:{replace:url=>opened.push(url)},close:()=>opened.push('closed')}:null},fetch:async(url,options)=>{calls.push({url,options});assert.ok(replies.length,'Unexpected extra request');const r=replies.shift();return {ok:r.status>=200&&r.status<300,status:r.status,json:async()=>r.data};}};
 runInNewContext(source,state);
 return {status,button,link,phrase,timers,calls,opened,get reloads(){return reloads;},submit:()=>events.submit({preventDefault(){}}),tick:async()=>{assert.ok(timers.length);timers.shift()();await settle();}};
}
const approval={approval_url:'https://control.example/?enrollment=fixture',phrase:'four-words'};
test('new enrollment keeps polling through pending errors and reloads only after a real successful check-in',async()=>{
 const b=browser({replies:[{status:200,data:approval},{status:500,data:{code:'sunrise_approval_pending',message:'Awaiting approval'}},{status:200,data:{policy_generation:2}}]});
 b.submit();await settle();assert.equal(b.reloads,0);assert.equal(b.button.disabled,true);assert.match(b.status.textContent,/Waiting for approval/);assert.equal(b.link.hidden,false);assert.deepEqual(b.opened,[approval.approval_url]);
 await b.tick();assert.equal(b.reloads,1);assert.match(b.status.textContent,/Check-in received/);assert.deepEqual(b.calls.map(c=>c.url),['/connect','/sync','/sync']);assert.ok(b.calls.every(c=>c.options.headers['X-WP-Nonce']==='fixture'&&c.options.credentials==='same-origin'));
});
test('HTTP 202 WP_Error is pending, never connected, and refreshed approval links replace stale ones',async()=>{
 const newer={approval_url:'https://control.example/?enrollment=renewed',phrase:'new-phrase'};
 const b=browser({pending:true,reconnect:true,replies:[{status:202,data:{code:'sunrise_reconnect_pending',data:{status:202,...newer}}},{status:200,data:{policy_generation:3}}]});
 await settle();assert.equal(b.reloads,0);assert.equal(b.link.href,newer.approval_url);assert.match(b.phrase.textContent,/new-phrase/);assert.equal(b.opened.length,0);
 await b.tick();assert.equal(b.reloads,1);assert.deepEqual(b.calls.map(c=>c.url),['/sync','/sync']);
});
test('reconnection opens the dedicated recovery route and still completes when popups are blocked',async()=>{
 const b=browser({reconnect:true,popup:false,replies:[{status:200,data:{status:'pending',...approval}},{status:202,data:{code:'sunrise_reconnect_pending'}},{status:200,data:{policy_generation:3}}]});
 b.submit();await settle();assert.equal(b.calls[0].url,'/reconnect');assert.equal(b.link.href,approval.approval_url);assert.equal(b.reloads,0);await b.tick();assert.equal(b.reloads,1);
});
test('an already-completed recovery reports success without another approval or polling cycle',async()=>{
 const b=browser({reconnect:true,replies:[{status:200,data:{status:'connected'}}]});b.submit();await settle();assert.equal(b.reloads,1);assert.equal(b.calls.length,1);assert.deepEqual(b.opened,[]);
});
test('permission errors stop polling and require a fresh WordPress page, without claiming connection success',async()=>{
 const b=browser({pending:true,replies:[{status:403,data:{code:'rest_cookie_invalid_nonce',message:'Expired'}}]});await settle();assert.equal(b.reloads,0);assert.equal(b.button.disabled,false);assert.equal(b.timers.length,0);assert.match(b.status.textContent,/Reload this WordPress page/);
});
test('approval links cannot navigate the popup outside the configured Control origin',async()=>{
 const b=browser({replies:[{status:200,data:{approval_url:'https://foreign.example/?enrollment=fixture'}}]});b.submit();await settle();assert.equal(b.reloads,0);assert.deepEqual(b.opened,['closed']);assert.match(b.status.textContent,/does not match/);
});
