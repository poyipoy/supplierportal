async () => {
    if (location.origin !== 'http://127.0.0.1:8129' || location.pathname !== '/profile/customization') throw new Error('Isolated QA customization only');
    const slider = document.querySelector('[data-density-slider]');
    const form = document.querySelector('#customizationForm');
    const controller = Alpine.$data(form);
    await document.fonts.ready;
    const originalScrollBehavior = document.documentElement.style.scrollBehavior;
    document.documentElement.style.scrollBehavior = 'auto';
    slider.scrollIntoView({block:'center', behavior:'instant'});
    await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    // Responsive sidebar transitions must settle before paired geometry samples.
    await Promise.all(document.getAnimations().filter(animation =>
        Number.isFinite(animation.effect?.getTiming().iterations)
    ).map(animation => animation.finished.catch(() => {})));
    await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    const rect = el => { const r=el.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height}; };
    const snap = () => ({ density:document.documentElement.dataset.density, scrollY, canonical:form.querySelector('[name=density]').value,
        label:form.querySelector('[data-density-label]').textContent, valueText:slider.getAttribute('aria-valuetext'), dirty:controller.dirtyCount,
        slider:rect(slider), actions:rect(form.querySelector('.ui-action-bar')),save:rect(form.querySelector('button[type=submit]')),
        frame:rect(form.querySelector('.ui-density-preview-frame')),previewTable:rect(form.querySelector('.ui-density-preview:not(.ui-density-preview__reserve) table')),
        activeLabel:rect(form.querySelector('[data-density-label]')), unsavedVisible:!form.querySelector('[data-density-unsaved]').hidden,
        discard:rect([...form.querySelectorAll('button')].find(b=>b.getAttribute('x-on:click')==='discard()')),
        settingsPadding:getComputedStyle(form.querySelector('.ui-density-settings-body')).paddingTop });
    let requests=0, storageWrites=0, redraws=0;
    const originalFetch=window.fetch, originalStorage=Storage.prototype.setItem;
    const originalXhr=XMLHttpRequest.prototype.send;
    window.fetch=function(...args){requests++;return originalFetch.apply(this,args);};
    XMLHttpRequest.prototype.send=function(...args){requests++;return originalXhr.apply(this,args);};
    Storage.prototype.setItem=function(...args){storageWrites++;return originalStorage.apply(this,args);};
    const draw=()=>redraws++;
    window.jQuery?.(document).on('draw.dt.densityQA',draw);
    const longTasks=[];
    const observer=new PerformanceObserver(list=>longTasks.push(...list.getEntries().map(e=>({start:e.startTime,duration:e.duration}))));
    observer.observe({type:'longtask',buffered:false});
    const levels=[],latencies=[];
    const paint=()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    try {
        slider.focus({preventScroll:true});
        for(let round=0;round<6;round++) for(let position=1;position<=5;position++) {
            slider.value=String(position);
            const start=performance.now(); slider.dispatchEvent(new Event('input',{bubbles:true})); await paint();
            latencies.push(performance.now()-start);
            if(round===0)levels.push(snap());
        }
        slider.value='2';slider.dispatchEvent(new Event('input',{bubbles:true}));await paint();
        const restored=snap();
        latencies.sort((a,b)=>a-b);
        return {viewport:{width:innerWidth,height:innerHeight},levels,restored,requests,storageWrites,redraws,
            latencies,p95:latencies[Math.ceil(latencies.length*.95)-1],longTasks,focusRetained:document.activeElement===slider,
            canonicalFields:[...new FormData(form).entries()].filter(([key])=>key==='density'),
            note:'Real page input events through production adapter; native keyboard tested separately. Existing polling can appear if a sample crosses its interval.'};
    } finally {
        document.documentElement.style.scrollBehavior = originalScrollBehavior;
        window.fetch=originalFetch;XMLHttpRequest.prototype.send=originalXhr;Storage.prototype.setItem=originalStorage;
        window.jQuery?.(document).off('draw.dt.densityQA',draw);observer.disconnect();
    }
}
