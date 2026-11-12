const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin.js'), 'utf8');
async function scenario(running, response, error) {
 const nodes = {'[data-scan-feedback]':{},'[data-scan-status]':{},'[data-scan-progress]':{}};
 let submit;
 const button = {value:'Run scan now',disabled:running,form:{addEventListener:(event, fn)=>{submit=fn;}}};
 nodes['[data-scan-button]']=button;
 const timers=[];
 let reloaded=false;
 const labels={starting:'Starting',scanning:'Scanning',running:'Running',completed:'Completed',scanFailed:'Failed',statusError:'Retrying',checked:'checked',failed:'failed'};
 const window={siteMonitorAdmin:{scanRunning:running,statusUrl:'/status',restNonce:'nonce',labels},setTimeout:(fn,delay)=>timers.push({fn,delay}),fetch:async()=>{if(error)throw new Error('offline');return {ok:true,json:async()=>response};},location:{reload:()=>{reloaded=true;}}};
 vm.runInNewContext(source,{window,document:{querySelector:s=>nodes[s]}});
 if(!running){submit();assert.equal(button.disabled,true);assert.equal(button.value,'Starting');assert.equal(nodes['[data-scan-feedback]'].textContent,'Starting');return;}
 await timers.shift().fn();
 await new Promise(resolve=>setImmediate(resolve));
 if(error){assert.equal(nodes['[data-scan-feedback]'].textContent,'Retrying');assert.equal(timers[0].delay,10000);return;}
 assert.equal(nodes['[data-scan-progress]'].textContent,'3 / 10 checked; 1 failed.');
 if(response.latest_scan.status==='running'){assert.equal(timers[0].delay,5000);assert.equal(button.disabled,true);}
 else {assert.equal(button.disabled,false);assert.equal(button.value,'Run scan now');timers[0].fn();assert.equal(reloaded,true);}
}
(async()=>{
 await scenario(false);
 await scenario(true,{latest_scan:{status:'running',checked_pages:3,total_pages:10,failed_pages:1}});
 await scenario(true,{latest_scan:{status:'completed',checked_pages:3,total_pages:10,failed_pages:1}});
 await scenario(true,null,true);
 console.log('PASS immediate feedback, progress polling, completion refresh, and error retry');
})().catch(error=>{console.error(error);process.exitCode=1;});
