/* The frame receives JSON responses only. Credentials and the WP REST nonce stay in this page. */
(()=>{
 'use strict';const cfg=sunriseDashboard,frame=document.getElementById('sunrise-dashboard'),status=document.getElementById('sunrise-dashboard-status');if(!frame)return;
 let active=0;const pending=new Set();
 addEventListener('message',async event=>{
  const msg=event.data;if(event.origin!==cfg.origin || event.source!==frame.contentWindow || msg?.channel!==cfg.channel || msg.type!=='sunrise-dashboard-request' || !/^[0-9a-f-]{36}$/.test(msg.id || '') || pending.has(msg.id))return;
  const send=value=>frame.contentWindow.postMessage({type:'sunrise-dashboard-response',channel:cfg.channel,id:msg.id,...value},cfg.origin);
  if(active>=12){send({error:'Too many dashboard requests. Try again shortly.'});return;}
  const body=JSON.stringify(msg.request);if(!body || body.length>131072){send({error:'Request too large.'});return;}
  pending.add(msg.id);active++;
  try{
   const response=await fetch(cfg.api,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},body,signal:AbortSignal.timeout(22000)}),result=await response.json();
   if(!response.ok){const code=result.data?.remote_code,message=code==='dashboard_approval_required'?'Sign in once to authorize network controls for this connection.':code || result.message || 'Sunrise could not load the network.';document.getElementById('sunrise-dashboard-permission').hidden=code!=='dashboard_approval_required';status.textContent='';send({error:message});return;}
   document.getElementById('sunrise-dashboard-permission').hidden=true;status.textContent='';send({result});
  }catch{status.textContent='';send({error:'service_unavailable'});}
  finally{active--;pending.delete(msg.id);}
 });
 const url=new URL(cfg.frame);url.searchParams.set('parent',location.origin);url.searchParams.set('channel',cfg.channel);url.searchParams.set('client',cfg.version);frame.src=url.href;
})();
