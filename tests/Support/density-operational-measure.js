async () => {
if(location.origin!=='http://127.0.0.1:8129'||!/^\/(admin|purchasing|finance|accounting|qc|ga|supplier|local-supplier)\//.test(location.pathname))throw new Error('Isolated operational QA only');
await document.fonts.ready; await new Promise(r=>setTimeout(r,700));
const read=s=>[...document.querySelectorAll(s)].slice(0,4).map(e=>{const r=e.getBoundingClientRect(),c=getComputedStyle(e);return {height:r.height,width:r.width,padding:c.padding,gap:c.gap,minHeight:c.minHeight};});
let draws=0; const draw=()=>draws++;window.jQuery?.(document).on('draw.dt.densityQA',draw);
const beforeRows=document.querySelectorAll('.table tbody tr').length;
const lengths=()=>window.jQuery?.fn.dataTable?.tables?window.jQuery.fn.dataTable.tables({api:true}).page.len():null;
const lengthBefore=lengths(),start=performance.now(),levels=[];
for(const density of ['spacious','comfortable','balanced','compact','dense']){
AdasiPreferences.previewDensity(density);await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));
levels.push({density,page:read('.content-area'),dashboard:read('.ui-dashboard-layout'),metric:read('.ui-metric-card'),card:read('.ui-card'),table:read('.ui-data-table'),cell:read('.table tbody td'),toolbar:read('.ui-toolbar'),form:read('.ui-form-section'),sidebar:read('.sidebar-menu .sidebar-link'),documentHeight:document.documentElement.scrollHeight,overflow:document.documentElement.scrollWidth-innerWidth});
}
const result={route:location.pathname,title:document.title,viewport:[innerWidth,innerHeight],saved:AdasiPreferences.density,levels,draws,beforeRows,afterRows:document.querySelectorAll('.table tbody tr').length,lengthBefore,lengthAfter:lengths(),requests:performance.getEntriesByType('resource').filter(e=>e.startTime>=start&&['fetch','xmlhttprequest'].includes(e.initiatorType)).length};
window.jQuery?.(document).off('draw.dt.densityQA',draw);AdasiPreferences.restoreSaved();return result;
}
