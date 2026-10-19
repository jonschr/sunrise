(()=>{
 'use strict';
 const config=window.sunriseConnect;if(!config)return;
 const pending=new Set(['sunrise_approval_pending','sunrise_reconnect_pending','sunrise_agent_busy']);
 const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
 async function request(url){
  const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce}});
  let data;try{data=await response.json();}catch{throw new Error('WordPress returned an unreadable response. Reload this page and try again.');}
  // WordPress can return WP_Error with HTTP 202 while recovery awaits approval.
  if(!response.ok || data.code){const error=new Error(data.message || 'The connection could not be completed.');error.code=data.code;error.details=data.data || {};if(response.status===403)error.message='Reload this WordPress page and try again.';throw error;}
  return data;
 }
 let active=false;
 for(const container of document.querySelectorAll('[data-sunrise-connection]')){
  const form=container.closest('form'),button=form.querySelector('button[type="submit"]'),status=container.querySelector('.sunrise-connect-status'),link=container.querySelector('[data-sunrise-approval]'),phrase=container.querySelector('[data-sunrise-phrase]');
  function approval(details){
   if(!details?.approval_url)return;
   const target=new URL(details.approval_url);
   if(!['http:','https:'].includes(target.protocol) || target.origin!==new URL(config.control).origin)throw new Error('The approval address does not match Sunrise Control. Reload this page and try again.');
   link.href=target.href;link.textContent='Open Sunrise Control to approve';link.className='button button-primary';link.hidden=false;button.hidden=true;
   phrase.textContent=details.phrase?'Verification phrase: '+details.phrase:'';
  }
  async function finish(start){
   if(active)return;active=true;button.disabled=true;if(!link.hidden){button.hidden=true;link.className='button button-primary';}
   let popup,opened=false;
   try{
    if(start){
     const reconnect=form.querySelector('[name="operation"]').value==='agent_reconnect';
     status.textContent=reconnect?'Verifying this connection…':'Opening Sunrise Control…';
     if(!reconnect){popup=window.open('about:blank','_blank');if(popup)popup.opener=null;}
     const enrollment=await request(reconnect?config.reconnect:config.enroll);
     if(enrollment.status==='connected'){popup?.close();status.textContent='Connected. Check-in received.';location.reload();return;}
     approval(enrollment);if(popup){popup.location.replace(link.href);opened=true;}
    }
    status.textContent='Waiting for approval in Sunrise Control. Keep this page open; the check-in will finish automatically.';
    const until=Date.now()+10*60*1000;
    while(Date.now()<until){
     try{
      await request(config.sync);
      status.textContent='Connected. Check-in received.';location.reload();return;
     }catch(error){
      if(!pending.has(error.code))throw error;
      approval(error.details);
      status.textContent=error.code==='sunrise_agent_busy'?'Another check-in is running. Waiting…':'Waiting for approval in Sunrise Control. Keep this page open; the check-in will finish automatically.';
     }
     await wait(3000);
    }
    status.textContent='Still waiting for approval. Use the Control link above, then continue here to resume checking.';
   }catch(error){if(!opened)popup?.close();status.textContent=error.message;}
   finally{active=false;button.disabled=false;button.hidden=false;if(!link.hidden){button.textContent='Resume checking';button.className='button button-secondary';}}
  }
  form.addEventListener('submit',event=>{event.preventDefault();finish(link.hidden);});
  if(container.dataset.pending==='1')finish(false);
 }
})();
