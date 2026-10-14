import test from 'node:test';
import assert from 'node:assert/strict';
import { searchableFreeInput } from '../../resources/js/searchable-free-input.js';

const config={url:'/pr-suggestions',value:'',messages:{loading:'loading',failed:'failed',minimum:'minimum',empty:'empty',results:':count results'}};
const response=data=>({ok:true,json:async()=>({data})});
function boot(fetch){const jobs=[];return {jobs,app:searchableFreeInput(config,{fetch,schedule:(fn,ms)=>{assert.equal(ms,300);jobs.push(fn);return fn;},cancel:fn=>{const i=jobs.indexOf(fn);if(i>=0)jobs.splice(i,1);}})};}

test('two characters and 300ms debounce prevent immediate lookup',async()=>{
    let calls=0;const {app,jobs}=boot(async()=>{calls++;return response([])});
    app.input('P');assert.equal(jobs.length,0);assert.equal(app.value,'P');
    app.input('PR');app.input('PR-NEW');assert.equal(jobs.length,1);assert.equal(calls,0);
    await jobs[0]();assert.equal(calls,1);assert.equal(app.value,'PR-NEW');
});
test('late responses cannot overwrite a newer query',async()=>{
    const pending=[];const {app,jobs}=boot(()=>new Promise(resolve=>pending.push(resolve)));
    app.input('OLD');const old=jobs[0]();app.input('NEW');const fresh=jobs[0]();
    pending[1](response([{pr_number:'NEW-1'}]));await fresh;
    pending[0](response([{pr_number:'OLD-1'}]));await old;
    assert.deepEqual(app.options,['NEW-1']);assert.equal(app.value,'NEW');
});
test('closing or selecting invalidates pending results',async()=>{
    let finish;const {app,jobs}=boot(()=>new Promise(resolve=>finish=resolve));
    app.input('PR');const work=jobs[0]();app.choose('000PR');finish(response([{pr_number:'OTHER'}]));await work;
    assert.equal(app.value,'000PR');assert.equal(app.open,false);assert.deepEqual(app.options,[]);
});
test('failure retains typed text and allows manual entry',async()=>{
    const {app,jobs}=boot(async()=>{throw Error('offline')});app.input('PR-MANUAL');await jobs[0]();
    assert.equal(app.status,'failed');assert.equal(app.value,'PR-MANUAL');app.enter();assert.equal(app.value,'PR-MANUAL');
});
test('results are bounded deduplicated and invalid values ignored',async()=>{
    const {app,jobs}=boot(async()=>response([{pr_number:'PR-A'},{pr_number:'pr-a'},{pr_number:null},...Array.from({length:40},(_,i)=>({pr_number:'PR-'+i}))]));
    app.input('PR');await jobs[0]();assert.equal(app.options.length,20);assert.equal(app.options[0],'PR-A');assert.equal(app.status,'20 results');
});
test('arrows choose highlighted result and unhighlighted Enter retains manual value',()=>{
    const {app}=boot();app.value='PR-MANUAL';app.options=['PR-A','PR-B'];app.enter();assert.equal(app.value,'PR-MANUAL');
    app.navigate(1);assert.equal(app.highlighted,0);app.navigate(1);assert.equal(app.highlighted,1);app.enter();assert.equal(app.value,'PR-B');
});
test('destroy cancels debounce and no response can reopen the combobox',()=>{
    const {app,jobs}=boot();app.input('PR');app.destroy();assert.equal(jobs.length,0);assert.equal(app.loading,false);
});

test('refocusing an open combobox preserves loaded options and keyboard position',()=>{
    const {app}=boot();app.input('PR');app.options=['PR-A'];app.highlighted=0;
    app.focus();assert.deepEqual(app.options,['PR-A']);assert.equal(app.highlighted,0);
});

test('Arrow Up from no selection highlights the last suggestion',()=>{
    const {app}=boot();app.options=['PR-A','PR-B'];app.navigate(-1);assert.equal(app.highlighted,1);app.enter();assert.equal(app.value,'PR-B');
});
