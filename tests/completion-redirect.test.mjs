import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { initCompletionRedirect } from '../webroot/assets/common/js/completion-redirect.js';

function fixture(seconds = 10) {
    const {document}=parseHTML(`<div data-completion-delay="${seconds}"><span data-completion-seconds>${seconds}</span><a data-completion-link href="https://panel.example/complete?id=abc%26def">click here</a></div>`);
    const root=document.querySelector('div');
    let time=0;
    const timers=[], returns=[];
    const options={baseUrl:'https://study.example/',now:()=>time,later:(fn)=>timers.push(fn),navigate:(url)=>returns.push(url)};
    return {root,timers,returns,options,advance:(ms)=>{time+=ms;timers.shift()();}};
}

test('ten-second default counts down and returns once to the exact individual link',()=>{
    const f=fixture(); initCompletionRedirect(f.root,f.options);
    assert.equal(f.returns.length,0);
    f.advance(1000); assert.equal(f.root.querySelector('span').textContent,'9');
    f.advance(9000); assert.deepEqual(f.returns,['https://panel.example/complete?id=abc%26def']);
    initCompletionRedirect(f.root,f.options); assert.equal(f.returns.length,1);
});

test('a delayed browser tick uses elapsed time instead of extending the debriefing',()=>{
    const f=fixture(120); initCompletionRedirect(f.root,f.options);
    f.advance(90000); assert.equal(f.root.querySelector('span').textContent,'30');
    assert.equal(f.returns.length,0); f.advance(30000); assert.equal(f.returns.length,1);
});

test('manual fallback works independently and suppresses a second automatic navigation',()=>{
    const f=fixture(); initCompletionRedirect(f.root,f.options);
    const link=f.root.querySelector('a');
    assert.equal(link.getAttribute('href'),'https://panel.example/complete?id=abc%26def');
    link.click(); f.advance(10000); assert.equal(f.returns.length,0);
});

test('zero redirects immediately and invalid timers do not navigate',()=>{
    const immediate=fixture(0); initCompletionRedirect(immediate.root,immediate.options);
    assert.equal(immediate.returns.length,1);
    for(const bad of ['1.5','-1','2147484','invalid']) {
        const f=fixture(bad); initCompletionRedirect(f.root,f.options);
        assert.equal(f.timers.length,0); assert.equal(f.returns.length,0);
    }
});

test('script URLs cannot be used as automatic returns',()=>{
    const f=fixture(); f.root.querySelector('a').setAttribute('href','javascript:alert(1)');
    initCompletionRedirect(f.root,f.options); assert.equal(f.timers.length,0);
});
