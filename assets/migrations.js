/* Preferences contain no authority. Every migration still requires independent Control approval. */
(()=>{
 'use strict';const cfg=sunriseMigrations,$=id=>document.getElementById(id),root=$('sunrise-migration-workbench');if(!root)return;
 let draft=cfg.draft,popup=null,channel=null,saving=false,dirty=false,timer=null,polling=false,known=new Map(),execution=false;
 const api=async(path='',data)=>{const r=await fetch(cfg.api+path,{method:data===undefined?'GET':'POST',credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce,'Content-Type':'application/json'},...(data===undefined?{}:{body:JSON.stringify(data)})});const value=await r.json();if(!r.ok)throw new Error(value.message || 'Sunrise could not complete this request.');return value;};
 const log=message=>{const item=document.createElement('li');item.textContent=new Date().toLocaleTimeString()+' · '+message;$('migration-log').prepend(item);while($('migration-log').children.length>100)$('migration-log').lastChild.remove();};
 function route(){const peer=draft.peer || {name:'Choose a connected site',url:''};return draft.direction==='push'?[cfg.site,peer]:[peer,cfg.site];}
 function render(){
  const [source,destination]=route();for(const [side,site] of [['source',source],['destination',destination]]){$('migration-'+side).textContent=site.name || site.url;$('migration-'+side+'-url').textContent=site.url;}
  $('migration-direction').textContent=draft.direction==='push'?'Push from this site to the destination.':'Pull from the source into this site.';
  $('migration-preset').value=draft.preset;$('migration-since').value=draft.since;$('migration-date').value=draft.date;$('migration-date-label').hidden=draft.since!=='date';
  for(const input of root.querySelectorAll('.migration-scope-input'))input.checked=draft.scopes.includes(input.value);
  for(const input of $('migration-settings').querySelectorAll('input'))input.checked=draft.names.includes(input.value);
  const unsupported=draft.scopes.some(scope=>scope!=='options'),incremental=draft.since!=='all';
  $('migration-range-note').textContent=incremental?'Incremental content and file transfers are in development. Settings have no reliable modification date.':'';
  $('migration-ready').textContent=unsupported?'This selection includes transfer handlers still in development.':incremental?'Choose all selected data for a settings transfer.':!draft.scopes.length?'Choose what to migrate.':!draft.names.length?'Choose at least one setting.':!draft.peer?'Choose a connected site.':'Prepare the exact changes, then approve the source and destination in Sunrise Control.';
  $('migration-prepare').disabled=unsupported || incremental || !draft.scopes.length || !draft.names.length || !draft.peer;
 }
 async function save(){if(saving)return;saving=true;$('migration-save').textContent='Saving…';try{while(dirty){dirty=false;const value=JSON.parse(JSON.stringify(draft));await api('',value);}$('migration-save').textContent='Saved';}catch(error){dirty=true;$('migration-save').textContent=error.message;log('Preferences were not saved.');}finally{saving=false;}}
 function changed(){dirty=true;render();clearTimeout(timer);timer=setTimeout(save,250);}
 root.addEventListener('change',event=>{
  const el=event.target;if(el.classList.contains('migration-scope-input')){draft.scopes=[...root.querySelectorAll('.migration-scope-input:checked')].map(i=>i.value);draft.preset='custom';}
  else if(el.closest('#migration-settings'))draft.names=[...$('migration-settings').querySelectorAll('input:checked')].map(i=>i.value);
  else if(el.id==='migration-preset'){draft.preset=el.value;const presets={settings:['options'],content:['posts','options','media'],files:['plugins','themes']};if(presets[el.value])draft.scopes=presets[el.value];}
  else if(el.id==='migration-since')draft.since=el.value;else if(el.id==='migration-date')draft.date=el.value;else return;changed();
 });
 $('migration-swap').onclick=()=>{draft.direction=draft.direction==='push'?'pull':'push';changed();};
 function openControl(mode){
  channel=crypto.randomUUID();const url=new URL(cfg.control);url.searchParams.set('view','migrations');url.searchParams.set('workbench_site',cfg.site.id);url.searchParams.set('workbench_origin',location.origin);url.searchParams.set('workbench_channel',channel);url.searchParams.set('workbench_mode',mode);
  if(mode==='prepare'){const [source,destination]=route();url.searchParams.set('migration_source',source.id);url.searchParams.set('migration_destination',destination.id);url.searchParams.set('migration_settings',draft.names.join(','));}
  popup=window.open(url.href,'sunrise-migration-'+channel,'popup,width=1100,height=850');if(!popup){$('migration-status').textContent='Allow the Sunrise Control window to open, then try again.';return;}log(mode==='pick'?'Choosing a connected site in Sunrise Control.':'Opening exact transfer preparation in Sunrise Control.');
 }
 $('migration-choose').onclick=()=>openControl('pick');$('migration-prepare').onclick=()=>openControl('prepare');
 window.addEventListener('message',event=>{
  if(event.origin!==new URL(cfg.control).origin || event.source!==popup || event.data?.channel!==channel || event.data?.type!=='sunrise-migration-peer')return;
  const peer=event.data.peer;if(!peer || !/^[0-9a-f-]{36}$/.test(peer.id) || peer.id===cfg.site.id || typeof peer.name!=='string' || peer.name.length>255 || typeof peer.url!=='string' || peer.url.length>2048)return;
  let url;try{url=new URL(peer.url);}catch{return;}if(!['https:','http:'].includes(url.protocol) || url.username || url.password || url.search || url.hash)return;
  draft.peer={id:peer.id,name:peer.name,url:peer.url};changed();log('Connected site selected.');popup.close();channel=null;popup=null;
 });
 const states={awaiting_source:'Waiting for source snapshot',awaiting_destination:'Comparing destination settings',ready:'Ready for approval',approved:'Approved · waiting for destination',running:'Applying destination changes',succeeded:'Completed',failed:'Failed',uncertain:'Outcome needs inspection',closed_unverified:'Closed without verification',cancelled:'Cancelled',expired:'Expired'};
 function display(data){
  execution=data.execution_available===true;$('migration-jobs').replaceChildren();for(const job of data.items || []){
   const label=states[job.status] || job.status;if(known.get(job.id)!==job.status){log(label+(job.outcome?' · '+job.outcome:''));known.set(job.id,job.status);}
   const row=document.createElement('div');row.className='migration-job';row.dataset.status=job.status;const heading=document.createElement('strong');heading.textContent=label;const detail=document.createElement('span');detail.textContent=(job.source_site_id===cfg.site.id?'Push':'Pull')+' · '+job.names.length+' settings · '+new Date(job.created_at).toLocaleString();row.append(heading,detail);
   if(job.failure_code){const error=document.createElement('small');error.textContent='Code: '+job.failure_code;row.append(error);}
   const link=document.createElement('a'),url=new URL(cfg.control);url.searchParams.set('view','migrations');url.searchParams.set('transfer',job.id);link.href=url.href;link.target='_blank';link.rel='noopener noreferrer';link.textContent=['ready','approved'].includes(job.status)?'Review approval':'Details';row.append(link);$('migration-jobs').append(row);
  }
  $('migration-status').textContent=!execution?'The connected service has not enabled transfer execution.':data.items?.length?'Status updates automatically.':'No migrations involving this connection yet.';
 }
 async function poll(){if(polling || document.hidden)return;polling=true;try{display(await api('/status'));}catch(error){$('migration-status').textContent=error.message;}finally{polling=false;}}
 $('migration-sync').onclick=async()=>{const button=$('migration-sync');button.disabled=true;log('Checking this site’s transfer work.');try{display(await api('/sync',{}));log('This site’s transfer check finished.');}catch(error){$('migration-status').textContent=error.message;log(error.message);}finally{button.disabled=false;}};
 render();poll();setInterval(poll,5000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
})();
