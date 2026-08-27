var e=(e,t)=>()=>(t||(e((t={exports:{}}).exports,t),e=null),t.exports);(function(){let e=document.createElement(`link`).relList;if(e&&e.supports&&e.supports(`modulepreload`))return;for(let e of document.querySelectorAll(`link[rel="modulepreload"]`))n(e);new MutationObserver(e=>{for(let t of e)if(t.type===`childList`)for(let e of t.addedNodes)e.tagName===`LINK`&&e.rel===`modulepreload`&&n(e)}).observe(document,{childList:!0,subtree:!0});function t(e){let t={};return e.integrity&&(t.integrity=e.integrity),e.referrerPolicy&&(t.referrerPolicy=e.referrerPolicy),t.credentials=e.crossOrigin===`use-credentials`?`include`:e.crossOrigin===`anonymous`?`omit`:`same-origin`,t}function n(e){if(e.ep)return;e.ep=!0;let n=t(e);fetch(e.href,n)}})();var t=e((e=>{var t=Symbol.for(`react.transitional.element`),n=Symbol.for(`react.portal`),r=Symbol.for(`react.fragment`),i=Symbol.for(`react.strict_mode`),a=Symbol.for(`react.profiler`),o=Symbol.for(`react.consumer`),s=Symbol.for(`react.context`),c=Symbol.for(`react.forward_ref`),l=Symbol.for(`react.suspense`),u=Symbol.for(`react.memo`),d=Symbol.for(`react.lazy`),f=Symbol.for(`react.activity`),p=Symbol.iterator;function m(e){return typeof e!=`object`||!e?null:(e=p&&e[p]||e[`@@iterator`],typeof e==`function`?e:null)}var h={isMounted:function(){return!1},enqueueForceUpdate:function(){},enqueueReplaceState:function(){},enqueueSetState:function(){}},g=Object.assign,_={};function v(e,t,n){this.props=e,this.context=t,this.refs=_,this.updater=n||h}v.prototype.isReactComponent={},v.prototype.setState=function(e,t){if(typeof e!=`object`&&typeof e!=`function`&&e!=null)throw Error(`takes an object of state variables to update or a function which returns an object of state variables.`);this.updater.enqueueSetState(this,e,t,`setState`)},v.prototype.forceUpdate=function(e){this.updater.enqueueForceUpdate(this,e,`forceUpdate`)};function y(){}y.prototype=v.prototype;function b(e,t,n){this.props=e,this.context=t,this.refs=_,this.updater=n||h}var x=b.prototype=new y;x.constructor=b,g(x,v.prototype),x.isPureReactComponent=!0;var S=Array.isArray;function C(){}var w={H:null,A:null,T:null,S:null},ee=Object.prototype.hasOwnProperty;function te(e,n,r){var i=r.ref;return{$$typeof:t,type:e,key:n,ref:i===void 0?null:i,props:r}}function ne(e,t){return te(e.type,t,e.props)}function T(e){return typeof e==`object`&&!!e&&e.$$typeof===t}function re(e){var t={"=":`=0`,":":`=2`};return`$`+e.replace(/[=:]/g,function(e){return t[e]})}var ie=/\/+/g;function ae(e,t){return typeof e==`object`&&e&&e.key!=null?re(``+e.key):t.toString(36)}function oe(e){switch(e.status){case`fulfilled`:return e.value;case`rejected`:throw e.reason;default:switch(typeof e.status==`string`?e.then(C,C):(e.status=`pending`,e.then(function(t){e.status===`pending`&&(e.status=`fulfilled`,e.value=t)},function(t){e.status===`pending`&&(e.status=`rejected`,e.reason=t)})),e.status){case`fulfilled`:return e.value;case`rejected`:throw e.reason}}throw e}function se(e,r,i,a,o){var s=typeof e;(s===`undefined`||s===`boolean`)&&(e=null);var c=!1;if(e===null)c=!0;else switch(s){case`bigint`:case`string`:case`number`:c=!0;break;case`object`:switch(e.$$typeof){case t:case n:c=!0;break;case d:return c=e._init,se(c(e._payload),r,i,a,o)}}if(c)return o=o(e),c=a===``?`.`+ae(e,0):a,S(o)?(i=``,c!=null&&(i=c.replace(ie,`$&/`)+`/`),se(o,r,i,``,function(e){return e})):o!=null&&(T(o)&&(o=ne(o,i+(o.key==null||e&&e.key===o.key?``:(``+o.key).replace(ie,`$&/`)+`/`)+c)),r.push(o)),1;c=0;var l=a===``?`.`:a+`:`;if(S(e))for(var u=0;u<e.length;u++)a=e[u],s=l+ae(a,u),c+=se(a,r,i,s,o);else if(u=m(e),typeof u==`function`)for(e=u.call(e),u=0;!(a=e.next()).done;)a=a.value,s=l+ae(a,u++),c+=se(a,r,i,s,o);else if(s===`object`){if(typeof e.then==`function`)return se(oe(e),r,i,a,o);throw r=String(e),Error(`Objects are not valid as a React child (found: `+(r===`[object Object]`?`object with keys {`+Object.keys(e).join(`, `)+`}`:r)+`). If you meant to render a collection of children, use an array instead.`)}return c}function ce(e,t,n){if(e==null)return e;var r=[],i=0;return se(e,r,``,``,function(e){return t.call(n,e,i++)}),r}function le(e){if(e._status===-1){var t=e._result;t=t(),t.then(function(t){(e._status===0||e._status===-1)&&(e._status=1,e._result=t)},function(t){(e._status===0||e._status===-1)&&(e._status=2,e._result=t)}),e._status===-1&&(e._status=0,e._result=t)}if(e._status===1)return e._result.default;throw e._result}var E=typeof reportError==`function`?reportError:function(e){if(typeof window==`object`&&typeof window.ErrorEvent==`function`){var t=new window.ErrorEvent(`error`,{bubbles:!0,cancelable:!0,message:typeof e==`object`&&e&&typeof e.message==`string`?String(e.message):String(e),error:e});if(!window.dispatchEvent(t))return}else if(typeof process==`object`&&typeof process.emit==`function`){process.emit(`uncaughtException`,e);return}console.error(e)},D={map:ce,forEach:function(e,t,n){ce(e,function(){t.apply(this,arguments)},n)},count:function(e){var t=0;return ce(e,function(){t++}),t},toArray:function(e){return ce(e,function(e){return e})||[]},only:function(e){if(!T(e))throw Error(`React.Children.only expected to receive a single React element child.`);return e}};e.Activity=f,e.Children=D,e.Component=v,e.Fragment=r,e.Profiler=a,e.PureComponent=b,e.StrictMode=i,e.Suspense=l,e.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE=w,e.__COMPILER_RUNTIME={__proto__:null,c:function(e){return w.H.useMemoCache(e)}},e.cache=function(e){return function(){return e.apply(null,arguments)}},e.cacheSignal=function(){return null},e.cloneElement=function(e,t,n){if(e==null)throw Error(`The argument must be a React element, but you passed `+e+`.`);var r=g({},e.props),i=e.key;if(t!=null)for(a in t.key!==void 0&&(i=``+t.key),t)!ee.call(t,a)||a===`key`||a===`__self`||a===`__source`||a===`ref`&&t.ref===void 0||(r[a]=t[a]);var a=arguments.length-2;if(a===1)r.children=n;else if(1<a){for(var o=Array(a),s=0;s<a;s++)o[s]=arguments[s+2];r.children=o}return te(e.type,i,r)},e.createContext=function(e){return e={$$typeof:s,_currentValue:e,_currentValue2:e,_threadCount:0,Provider:null,Consumer:null},e.Provider=e,e.Consumer={$$typeof:o,_context:e},e},e.createElement=function(e,t,n){var r,i={},a=null;if(t!=null)for(r in t.key!==void 0&&(a=``+t.key),t)ee.call(t,r)&&r!==`key`&&r!==`__self`&&r!==`__source`&&(i[r]=t[r]);var o=arguments.length-2;if(o===1)i.children=n;else if(1<o){for(var s=Array(o),c=0;c<o;c++)s[c]=arguments[c+2];i.children=s}if(e&&e.defaultProps)for(r in o=e.defaultProps,o)i[r]===void 0&&(i[r]=o[r]);return te(e,a,i)},e.createRef=function(){return{current:null}},e.forwardRef=function(e){return{$$typeof:c,render:e}},e.isValidElement=T,e.lazy=function(e){return{$$typeof:d,_payload:{_status:-1,_result:e},_init:le}},e.memo=function(e,t){return{$$typeof:u,type:e,compare:t===void 0?null:t}},e.startTransition=function(e){var t=w.T,n={};w.T=n;try{var r=e(),i=w.S;i!==null&&i(n,r),typeof r==`object`&&r&&typeof r.then==`function`&&r.then(C,E)}catch(e){E(e)}finally{t!==null&&n.types!==null&&(t.types=n.types),w.T=t}},e.unstable_useCacheRefresh=function(){return w.H.useCacheRefresh()},e.use=function(e){return w.H.use(e)},e.useActionState=function(e,t,n){return w.H.useActionState(e,t,n)},e.useCallback=function(e,t){return w.H.useCallback(e,t)},e.useContext=function(e){return w.H.useContext(e)},e.useDebugValue=function(){},e.useDeferredValue=function(e,t){return w.H.useDeferredValue(e,t)},e.useEffect=function(e,t){return w.H.useEffect(e,t)},e.useEffectEvent=function(e){return w.H.useEffectEvent(e)},e.useId=function(){return w.H.useId()},e.useImperativeHandle=function(e,t,n){return w.H.useImperativeHandle(e,t,n)},e.useInsertionEffect=function(e,t){return w.H.useInsertionEffect(e,t)},e.useLayoutEffect=function(e,t){return w.H.useLayoutEffect(e,t)},e.useMemo=function(e,t){return w.H.useMemo(e,t)},e.useOptimistic=function(e,t){return w.H.useOptimistic(e,t)},e.useReducer=function(e,t,n){return w.H.useReducer(e,t,n)},e.useRef=function(e){return w.H.useRef(e)},e.useState=function(e){return w.H.useState(e)},e.useSyncExternalStore=function(e,t,n){return w.H.useSyncExternalStore(e,t,n)},e.useTransition=function(){return w.H.useTransition()},e.version=`19.2.8`})),n=e(((e,n)=>{n.exports=t()})),r=e((e=>{function t(e,t){var n=e.length;e.push(t);a:for(;0<n;){var r=n-1>>>1,a=e[r];if(0<i(a,t))e[r]=t,e[n]=a,n=r;else break a}}function n(e){return e.length===0?null:e[0]}function r(e){if(e.length===0)return null;var t=e[0],n=e.pop();if(n!==t){e[0]=n;a:for(var r=0,a=e.length,o=a>>>1;r<o;){var s=2*(r+1)-1,c=e[s],l=s+1,u=e[l];if(0>i(c,n))l<a&&0>i(u,c)?(e[r]=u,e[l]=n,r=l):(e[r]=c,e[s]=n,r=s);else if(l<a&&0>i(u,n))e[r]=u,e[l]=n,r=l;else break a}}return t}function i(e,t){var n=e.sortIndex-t.sortIndex;return n===0?e.id-t.id:n}if(e.unstable_now=void 0,typeof performance==`object`&&typeof performance.now==`function`){var a=performance;e.unstable_now=function(){return a.now()}}else{var o=Date,s=o.now();e.unstable_now=function(){return o.now()-s}}var c=[],l=[],u=1,d=null,f=3,p=!1,m=!1,h=!1,g=!1,_=typeof setTimeout==`function`?setTimeout:null,v=typeof clearTimeout==`function`?clearTimeout:null,y=typeof setImmediate<`u`?setImmediate:null;function b(e){for(var i=n(l);i!==null;){if(i.callback===null)r(l);else if(i.startTime<=e)r(l),i.sortIndex=i.expirationTime,t(c,i);else break;i=n(l)}}function x(e){if(h=!1,b(e),!m){if(n(c)!==null)m=!0,S||(S=!0,T());else{var t=n(l);t!==null&&ae(x,t.startTime-e)}}}var S=!1,C=-1,w=5,ee=-1;function te(){return g?!0:!(e.unstable_now()-ee<w)}function ne(){if(g=!1,S){var t=e.unstable_now();ee=t;var i=!0;try{a:{m=!1,h&&(h=!1,v(C),C=-1),p=!0;var a=f;try{b:{for(b(t),d=n(c);d!==null&&!(d.expirationTime>t&&te());){var o=d.callback;if(typeof o==`function`){d.callback=null,f=d.priorityLevel;var s=o(d.expirationTime<=t);if(t=e.unstable_now(),typeof s==`function`){d.callback=s,b(t),i=!0;break b}d===n(c)&&r(c),b(t)}else r(c);d=n(c)}if(d!==null)i=!0;else{var u=n(l);u!==null&&ae(x,u.startTime-t),i=!1}}break a}finally{d=null,f=a,p=!1}i=void 0}}finally{i?T():S=!1}}}var T;if(typeof y==`function`)T=function(){y(ne)};else if(typeof MessageChannel<`u`){var re=new MessageChannel,ie=re.port2;re.port1.onmessage=ne,T=function(){ie.postMessage(null)}}else T=function(){_(ne,0)};function ae(t,n){C=_(function(){t(e.unstable_now())},n)}e.unstable_IdlePriority=5,e.unstable_ImmediatePriority=1,e.unstable_LowPriority=4,e.unstable_NormalPriority=3,e.unstable_Profiling=null,e.unstable_UserBlockingPriority=2,e.unstable_cancelCallback=function(e){e.callback=null},e.unstable_forceFrameRate=function(e){0>e||125<e?console.error(`forceFrameRate takes a positive int between 0 and 125, forcing frame rates higher than 125 fps is not supported`):w=0<e?Math.floor(1e3/e):5},e.unstable_getCurrentPriorityLevel=function(){return f},e.unstable_next=function(e){switch(f){case 1:case 2:case 3:var t=3;break;default:t=f}var n=f;f=t;try{return e()}finally{f=n}},e.unstable_requestPaint=function(){g=!0},e.unstable_runWithPriority=function(e,t){switch(e){case 1:case 2:case 3:case 4:case 5:break;default:e=3}var n=f;f=e;try{return t()}finally{f=n}},e.unstable_scheduleCallback=function(r,i,a){var o=e.unstable_now();switch(typeof a==`object`&&a?(a=a.delay,a=typeof a==`number`&&0<a?o+a:o):a=o,r){case 1:var s=-1;break;case 2:s=250;break;case 5:s=1073741823;break;case 4:s=1e4;break;default:s=5e3}return s=a+s,r={id:u++,callback:i,priorityLevel:r,startTime:a,expirationTime:s,sortIndex:-1},a>o?(r.sortIndex=a,t(l,r),n(c)===null&&r===n(l)&&(h?(v(C),C=-1):h=!0,ae(x,a-o))):(r.sortIndex=s,t(c,r),m||p||(m=!0,S||(S=!0,T()))),r},e.unstable_shouldYield=te,e.unstable_wrapCallback=function(e){var t=f;return function(){var n=f;f=t;try{return e.apply(this,arguments)}finally{f=n}}}})),i=e(((e,t)=>{t.exports=r()})),a=e((e=>{var t=n();function r(e){var t=`https://react.dev/errors/`+e;if(1<arguments.length){t+=`?args[]=`+encodeURIComponent(arguments[1]);for(var n=2;n<arguments.length;n++)t+=`&args[]=`+encodeURIComponent(arguments[n])}return`Minified React error #`+e+`; visit `+t+` for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`}function i(){}var a={d:{f:i,r:function(){throw Error(r(522))},D:i,C:i,L:i,m:i,X:i,S:i,M:i},p:0,findDOMNode:null},o=Symbol.for(`react.portal`);function s(e,t,n){var r=3<arguments.length&&arguments[3]!==void 0?arguments[3]:null;return{$$typeof:o,key:r==null?null:``+r,children:e,containerInfo:t,implementation:n}}var c=t.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE;function l(e,t){if(e===`font`)return``;if(typeof t==`string`)return t===`use-credentials`?t:``}e.__DOM_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE=a,e.createPortal=function(e,t){var n=2<arguments.length&&arguments[2]!==void 0?arguments[2]:null;if(!t||t.nodeType!==1&&t.nodeType!==9&&t.nodeType!==11)throw Error(r(299));return s(e,t,null,n)},e.flushSync=function(e){var t=c.T,n=a.p;try{if(c.T=null,a.p=2,e)return e()}finally{c.T=t,a.p=n,a.d.f()}},e.preconnect=function(e,t){typeof e==`string`&&(t?(t=t.crossOrigin,t=typeof t==`string`?t===`use-credentials`?t:``:void 0):t=null,a.d.C(e,t))},e.prefetchDNS=function(e){typeof e==`string`&&a.d.D(e)},e.preinit=function(e,t){if(typeof e==`string`&&t&&typeof t.as==`string`){var n=t.as,r=l(n,t.crossOrigin),i=typeof t.integrity==`string`?t.integrity:void 0,o=typeof t.fetchPriority==`string`?t.fetchPriority:void 0;n===`style`?a.d.S(e,typeof t.precedence==`string`?t.precedence:void 0,{crossOrigin:r,integrity:i,fetchPriority:o}):n===`script`&&a.d.X(e,{crossOrigin:r,integrity:i,fetchPriority:o,nonce:typeof t.nonce==`string`?t.nonce:void 0})}},e.preinitModule=function(e,t){if(typeof e==`string`){if(typeof t==`object`&&t){if(t.as==null||t.as===`script`){var n=l(t.as,t.crossOrigin);a.d.M(e,{crossOrigin:n,integrity:typeof t.integrity==`string`?t.integrity:void 0,nonce:typeof t.nonce==`string`?t.nonce:void 0})}}else t??a.d.M(e)}},e.preload=function(e,t){if(typeof e==`string`&&typeof t==`object`&&t&&typeof t.as==`string`){var n=t.as,r=l(n,t.crossOrigin);a.d.L(e,n,{crossOrigin:r,integrity:typeof t.integrity==`string`?t.integrity:void 0,nonce:typeof t.nonce==`string`?t.nonce:void 0,type:typeof t.type==`string`?t.type:void 0,fetchPriority:typeof t.fetchPriority==`string`?t.fetchPriority:void 0,referrerPolicy:typeof t.referrerPolicy==`string`?t.referrerPolicy:void 0,imageSrcSet:typeof t.imageSrcSet==`string`?t.imageSrcSet:void 0,imageSizes:typeof t.imageSizes==`string`?t.imageSizes:void 0,media:typeof t.media==`string`?t.media:void 0})}},e.preloadModule=function(e,t){if(typeof e==`string`){if(t){var n=l(t.as,t.crossOrigin);a.d.m(e,{as:typeof t.as==`string`&&t.as!==`script`?t.as:void 0,crossOrigin:n,integrity:typeof t.integrity==`string`?t.integrity:void 0})}else a.d.m(e)}},e.requestFormReset=function(e){a.d.r(e)},e.unstable_batchedUpdates=function(e,t){return e(t)},e.useFormState=function(e,t,n){return c.H.useFormState(e,t,n)},e.useFormStatus=function(){return c.H.useHostTransitionStatus()},e.version=`19.2.8`})),o=e(((e,t)=>{function n(){if(!(typeof __REACT_DEVTOOLS_GLOBAL_HOOK__>`u`||typeof __REACT_DEVTOOLS_GLOBAL_HOOK__.checkDCE!=`function`))try{__REACT_DEVTOOLS_GLOBAL_HOOK__.checkDCE(n)}catch(e){console.error(e)}}n(),t.exports=a()})),s=e((e=>{var t=i(),r=n(),a=o();function s(e){var t=`https://react.dev/errors/`+e;if(1<arguments.length){t+=`?args[]=`+encodeURIComponent(arguments[1]);for(var n=2;n<arguments.length;n++)t+=`&args[]=`+encodeURIComponent(arguments[n])}return`Minified React error #`+e+`; visit `+t+` for the full message or use the non-minified dev environment for full errors and additional helpful warnings.`}function c(e){return!(!e||e.nodeType!==1&&e.nodeType!==9&&e.nodeType!==11)}function l(e){var t=e,n=e;if(e.alternate)for(;t.return;)t=t.return;else{e=t;do t=e,t.flags&4098&&(n=t.return),e=t.return;while(e)}return t.tag===3?n:null}function u(e){if(e.tag===13){var t=e.memoizedState;if(t===null&&(e=e.alternate,e!==null&&(t=e.memoizedState)),t!==null)return t.dehydrated}return null}function d(e){if(e.tag===31){var t=e.memoizedState;if(t===null&&(e=e.alternate,e!==null&&(t=e.memoizedState)),t!==null)return t.dehydrated}return null}function f(e){if(l(e)!==e)throw Error(s(188))}function p(e){var t=e.alternate;if(!t){if(t=l(e),t===null)throw Error(s(188));return t===e?e:null}for(var n=e,r=t;;){var i=n.return;if(i===null)break;var a=i.alternate;if(a===null){if(r=i.return,r!==null){n=r;continue}break}if(i.child===a.child){for(a=i.child;a;){if(a===n)return f(i),e;if(a===r)return f(i),t;a=a.sibling}throw Error(s(188))}if(n.return!==r.return)n=i,r=a;else{for(var o=!1,c=i.child;c;){if(c===n){o=!0,n=i,r=a;break}if(c===r){o=!0,r=i,n=a;break}c=c.sibling}if(!o){for(c=a.child;c;){if(c===n){o=!0,n=a,r=i;break}if(c===r){o=!0,r=a,n=i;break}c=c.sibling}if(!o)throw Error(s(189))}}if(n.alternate!==r)throw Error(s(190))}if(n.tag!==3)throw Error(s(188));return n.stateNode.current===n?e:t}function m(e){var t=e.tag;if(t===5||t===26||t===27||t===6)return e;for(e=e.child;e!==null;){if(t=m(e),t!==null)return t;e=e.sibling}return null}var h=Object.assign,g=Symbol.for(`react.element`),_=Symbol.for(`react.transitional.element`),v=Symbol.for(`react.portal`),y=Symbol.for(`react.fragment`),b=Symbol.for(`react.strict_mode`),x=Symbol.for(`react.profiler`),S=Symbol.for(`react.consumer`),C=Symbol.for(`react.context`),w=Symbol.for(`react.forward_ref`),ee=Symbol.for(`react.suspense`),te=Symbol.for(`react.suspense_list`),ne=Symbol.for(`react.memo`),T=Symbol.for(`react.lazy`),re=Symbol.for(`react.activity`),ie=Symbol.for(`react.memo_cache_sentinel`),ae=Symbol.iterator;function oe(e){return typeof e!=`object`||!e?null:(e=ae&&e[ae]||e[`@@iterator`],typeof e==`function`?e:null)}var se=Symbol.for(`react.client.reference`);function ce(e){if(e==null)return null;if(typeof e==`function`)return e.$$typeof===se?null:e.displayName||e.name||null;if(typeof e==`string`)return e;switch(e){case y:return`Fragment`;case x:return`Profiler`;case b:return`StrictMode`;case ee:return`Suspense`;case te:return`SuspenseList`;case re:return`Activity`}if(typeof e==`object`)switch(e.$$typeof){case v:return`Portal`;case C:return e.displayName||`Context`;case S:return(e._context.displayName||`Context`)+`.Consumer`;case w:var t=e.render;return e=e.displayName,e||=(e=t.displayName||t.name||``,e===``?`ForwardRef`:`ForwardRef(`+e+`)`),e;case ne:return t=e.displayName||null,t===null?ce(e.type)||`Memo`:t;case T:t=e._payload,e=e._init;try{return ce(e(t))}catch{}}return null}var le=Array.isArray,E=r.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE,D=a.__DOM_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE,ue={pending:!1,data:null,method:null,action:null},de=[],fe=-1;function pe(e){return{current:e}}function me(e){0>fe||(e.current=de[fe],de[fe]=null,fe--)}function O(e,t){fe++,de[fe]=e.current,e.current=t}var he=pe(null),ge=pe(null),_e=pe(null),ve=pe(null);function ye(e,t){switch(O(_e,t),O(ge,e),O(he,null),t.nodeType){case 9:case 11:e=(e=t.documentElement)&&(e=e.namespaceURI)?Vd(e):0;break;default:if(e=t.tagName,t=t.namespaceURI)t=Vd(t),e=Hd(t,e);else switch(e){case`svg`:e=1;break;case`math`:e=2;break;default:e=0}}me(he),O(he,e)}function be(){me(he),me(ge),me(_e)}function xe(e){e.memoizedState!==null&&O(ve,e);var t=he.current,n=Hd(t,e.type);t!==n&&(O(ge,e),O(he,n))}function Se(e){ge.current===e&&(me(he),me(ge)),ve.current===e&&(me(ve),Qf._currentValue=ue)}var Ce,we;function Te(e){if(Ce===void 0)try{throw Error()}catch(e){var t=e.stack.trim().match(/\n( *(at )?)/);Ce=t&&t[1]||``,we=-1<e.stack.indexOf(`
    at`)?` (<anonymous>)`:-1<e.stack.indexOf(`@`)?`@unknown:0:0`:``}return`
`+Ce+e+we}var Ee=!1;function De(e,t){if(!e||Ee)return``;Ee=!0;var n=Error.prepareStackTrace;Error.prepareStackTrace=void 0;try{var r={DetermineComponentFrameRoot:function(){try{if(t){var n=function(){throw Error()};if(Object.defineProperty(n.prototype,"props",{set:function(){throw Error()}}),typeof Reflect==`object`&&Reflect.construct){try{Reflect.construct(n,[])}catch(e){var r=e}Reflect.construct(e,[],n)}else{try{n.call()}catch(e){r=e}e.call(n.prototype)}}else{try{throw Error()}catch(e){r=e}(n=e())&&typeof n.catch==`function`&&n.catch(function(){})}}catch(e){if(e&&r&&typeof e.stack==`string`)return[e.stack,r.stack]}return[null,null]}};r.DetermineComponentFrameRoot.displayName=`DetermineComponentFrameRoot`;var i=Object.getOwnPropertyDescriptor(r.DetermineComponentFrameRoot,`name`);i&&i.configurable&&Object.defineProperty(r.DetermineComponentFrameRoot,"name",{value:`DetermineComponentFrameRoot`});var a=r.DetermineComponentFrameRoot(),o=a[0],s=a[1];if(o&&s){var c=o.split(`
`),l=s.split(`
`);for(i=r=0;r<c.length&&!c[r].includes(`DetermineComponentFrameRoot`);)r++;for(;i<l.length&&!l[i].includes(`DetermineComponentFrameRoot`);)i++;if(r===c.length||i===l.length)for(r=c.length-1,i=l.length-1;1<=r&&0<=i&&c[r]!==l[i];)i--;for(;1<=r&&0<=i;r--,i--)if(c[r]!==l[i]){if(r!==1||i!==1)do if(r--,i--,0>i||c[r]!==l[i]){var u=`
`+c[r].replace(` at new `,` at `);return e.displayName&&u.includes(`<anonymous>`)&&(u=u.replace(`<anonymous>`,e.displayName)),u}while(1<=r&&0<=i);break}}}finally{Ee=!1,Error.prepareStackTrace=n}return(n=e?e.displayName||e.name:``)?Te(n):``}function Oe(e,t){switch(e.tag){case 26:case 27:case 5:return Te(e.type);case 16:return Te(`Lazy`);case 13:return e.child!==t&&t!==null?Te(`Suspense Fallback`):Te(`Suspense`);case 19:return Te(`SuspenseList`);case 0:case 15:return De(e.type,!1);case 11:return De(e.type.render,!1);case 1:return De(e.type,!0);case 31:return Te(`Activity`);default:return``}}function ke(e){try{var t=``,n=null;do t+=Oe(e,n),n=e,e=e.return;while(e);return t}catch(e){return`
Error generating stack: `+e.message+`
`+e.stack}}var Ae=Object.prototype.hasOwnProperty,je=t.unstable_scheduleCallback,Me=t.unstable_cancelCallback,Ne=t.unstable_shouldYield,Pe=t.unstable_requestPaint,Fe=t.unstable_now,Ie=t.unstable_getCurrentPriorityLevel,Le=t.unstable_ImmediatePriority,Re=t.unstable_UserBlockingPriority,ze=t.unstable_NormalPriority,Be=t.unstable_LowPriority,Ve=t.unstable_IdlePriority,He=t.log,Ue=t.unstable_setDisableYieldValue,We=null,Ge=null;function Ke(e){if(typeof He==`function`&&Ue(e),Ge&&typeof Ge.setStrictMode==`function`)try{Ge.setStrictMode(We,e)}catch{}}var qe=Math.clz32?Math.clz32:Xe,Je=Math.log,Ye=Math.LN2;function Xe(e){return e>>>=0,e===0?32:31-(Je(e)/Ye|0)|0}var Ze=256,Qe=262144,$e=4194304;function et(e){var t=e&42;if(t!==0)return t;switch(e&-e){case 1:return 1;case 2:return 2;case 4:return 4;case 8:return 8;case 16:return 16;case 32:return 32;case 64:return 64;case 128:return 128;case 256:case 512:case 1024:case 2048:case 4096:case 8192:case 16384:case 32768:case 65536:case 131072:return e&261888;case 262144:case 524288:case 1048576:case 2097152:return e&3932160;case 4194304:case 8388608:case 16777216:case 33554432:return e&62914560;case 67108864:return 67108864;case 134217728:return 134217728;case 268435456:return 268435456;case 536870912:return 536870912;case 1073741824:return 0;default:return e}}function tt(e,t,n){var r=e.pendingLanes;if(r===0)return 0;var i=0,a=e.suspendedLanes,o=e.pingedLanes;e=e.warmLanes;var s=r&134217727;return s===0?(s=r&~a,s===0?o===0?n||(n=r&~e,n!==0&&(i=et(n))):i=et(o):i=et(s)):(r=s&~a,r===0?(o&=s,o===0?n||(n=s&~e,n!==0&&(i=et(n))):i=et(o)):i=et(r)),i===0?0:t!==0&&t!==i&&(t&a)===0&&(a=i&-i,n=t&-t,a>=n||a===32&&n&4194048)?t:i}function nt(e,t){return(e.pendingLanes&~(e.suspendedLanes&~e.pingedLanes)&t)===0}function rt(e,t){switch(e){case 1:case 2:case 4:case 8:case 64:return t+250;case 16:case 32:case 128:case 256:case 512:case 1024:case 2048:case 4096:case 8192:case 16384:case 32768:case 65536:case 131072:case 262144:case 524288:case 1048576:case 2097152:return t+5e3;case 4194304:case 8388608:case 16777216:case 33554432:return-1;case 67108864:case 134217728:case 268435456:case 536870912:case 1073741824:return-1;default:return-1}}function it(){var e=$e;return $e<<=1,!($e&62914560)&&($e=4194304),e}function at(e){for(var t=[],n=0;31>n;n++)t.push(e);return t}function ot(e,t){e.pendingLanes|=t,t!==268435456&&(e.suspendedLanes=0,e.pingedLanes=0,e.warmLanes=0)}function st(e,t,n,r,i,a){var o=e.pendingLanes;e.pendingLanes=n,e.suspendedLanes=0,e.pingedLanes=0,e.warmLanes=0,e.expiredLanes&=n,e.entangledLanes&=n,e.errorRecoveryDisabledLanes&=n,e.shellSuspendCounter=0;var s=e.entanglements,c=e.expirationTimes,l=e.hiddenUpdates;for(n=o&~n;0<n;){var u=31-qe(n),d=1<<u;s[u]=0,c[u]=-1;var f=l[u];if(f!==null)for(l[u]=null,u=0;u<f.length;u++){var p=f[u];p!==null&&(p.lane&=-536870913)}n&=~d}r!==0&&ct(e,r,0),a!==0&&i===0&&e.tag!==0&&(e.suspendedLanes|=a&~(o&~t))}function ct(e,t,n){e.pendingLanes|=t,e.suspendedLanes&=~t;var r=31-qe(t);e.entangledLanes|=t,e.entanglements[r]=e.entanglements[r]|1073741824|n&261930}function lt(e,t){var n=e.entangledLanes|=t;for(e=e.entanglements;n;){var r=31-qe(n),i=1<<r;i&t|e[r]&t&&(e[r]|=t),n&=~i}}function ut(e,t){var n=t&-t;return n=n&42?1:dt(n),(n&(e.suspendedLanes|t))===0?n:0}function dt(e){switch(e){case 2:e=1;break;case 8:e=4;break;case 32:e=16;break;case 256:case 512:case 1024:case 2048:case 4096:case 8192:case 16384:case 32768:case 65536:case 131072:case 262144:case 524288:case 1048576:case 2097152:case 4194304:case 8388608:case 16777216:case 33554432:e=128;break;case 268435456:e=134217728;break;default:e=0}return e}function ft(e){return e&=-e,2<e?8<e?e&134217727?32:268435456:8:2}function pt(){var e=D.p;return e===0?(e=window.event,e===void 0?32:mp(e.type)):e}function mt(e,t){var n=D.p;try{return D.p=e,t()}finally{D.p=n}}var ht=Math.random().toString(36).slice(2),gt=`__reactFiber$`+ht,_t=`__reactProps$`+ht,vt=`__reactContainer$`+ht,yt=`__reactEvents$`+ht,bt=`__reactListeners$`+ht,xt=`__reactHandles$`+ht,St=`__reactResources$`+ht,Ct=`__reactMarker$`+ht;function wt(e){delete e[gt],delete e[_t],delete e[yt],delete e[bt],delete e[xt]}function Tt(e){var t=e[gt];if(t)return t;for(var n=e.parentNode;n;){if(t=n[vt]||n[gt]){if(n=t.alternate,t.child!==null||n!==null&&n.child!==null)for(e=df(e);e!==null;){if(n=e[gt])return n;e=df(e)}return t}e=n,n=e.parentNode}return null}function Et(e){if(e=e[gt]||e[vt]){var t=e.tag;if(t===5||t===6||t===13||t===31||t===26||t===27||t===3)return e}return null}function Dt(e){var t=e.tag;if(t===5||t===26||t===27||t===6)return e.stateNode;throw Error(s(33))}function Ot(e){var t=e[St];return t||=e[St]={hoistableStyles:new Map,hoistableScripts:new Map},t}function k(e){e[Ct]=!0}var kt=new Set,At={};function jt(e,t){Mt(e,t),Mt(e+`Capture`,t)}function Mt(e,t){for(At[e]=t,e=0;e<t.length;e++)kt.add(t[e])}var Nt=RegExp(`^[:A-Z_a-z\\u00C0-\\u00D6\\u00D8-\\u00F6\\u00F8-\\u02FF\\u0370-\\u037D\\u037F-\\u1FFF\\u200C-\\u200D\\u2070-\\u218F\\u2C00-\\u2FEF\\u3001-\\uD7FF\\uF900-\\uFDCF\\uFDF0-\\uFFFD][:A-Z_a-z\\u00C0-\\u00D6\\u00D8-\\u00F6\\u00F8-\\u02FF\\u0370-\\u037D\\u037F-\\u1FFF\\u200C-\\u200D\\u2070-\\u218F\\u2C00-\\u2FEF\\u3001-\\uD7FF\\uF900-\\uFDCF\\uFDF0-\\uFFFD\\-.0-9\\u00B7\\u0300-\\u036F\\u203F-\\u2040]*$`),Pt={},Ft={};function It(e){return Ae.call(Ft,e)?!0:Ae.call(Pt,e)?!1:Nt.test(e)?Ft[e]=!0:(Pt[e]=!0,!1)}function Lt(e,t,n){if(It(t)){if(n===null)e.removeAttribute(t);else{switch(typeof n){case`undefined`:case`function`:case`symbol`:e.removeAttribute(t);return;case`boolean`:var r=t.toLowerCase().slice(0,5);if(r!==`data-`&&r!==`aria-`){e.removeAttribute(t);return}}e.setAttribute(t,``+n)}}}function Rt(e,t,n){if(n===null)e.removeAttribute(t);else{switch(typeof n){case`undefined`:case`function`:case`symbol`:case`boolean`:e.removeAttribute(t);return}e.setAttribute(t,``+n)}}function zt(e,t,n,r){if(r===null)e.removeAttribute(n);else{switch(typeof r){case`undefined`:case`function`:case`symbol`:case`boolean`:e.removeAttribute(n);return}e.setAttributeNS(t,n,``+r)}}function Bt(e){switch(typeof e){case`bigint`:case`boolean`:case`number`:case`string`:case`undefined`:return e;case`object`:return e;default:return``}}function Vt(e){var t=e.type;return(e=e.nodeName)&&e.toLowerCase()===`input`&&(t===`checkbox`||t===`radio`)}function Ht(e,t,n){var r=Object.getOwnPropertyDescriptor(e.constructor.prototype,t);if(!e.hasOwnProperty(t)&&r!==void 0&&typeof r.get==`function`&&typeof r.set==`function`){var i=r.get,a=r.set;return Object.defineProperty(e,t,{configurable:!0,get:function(){return i.call(this)},set:function(e){n=``+e,a.call(this,e)}}),Object.defineProperty(e,t,{enumerable:r.enumerable}),{getValue:function(){return n},setValue:function(e){n=``+e},stopTracking:function(){e._valueTracker=null,delete e[t]}}}}function Ut(e){if(!e._valueTracker){var t=Vt(e)?`checked`:`value`;e._valueTracker=Ht(e,t,``+e[t])}}function Wt(e){if(!e)return!1;var t=e._valueTracker;if(!t)return!0;var n=t.getValue(),r=``;return e&&(r=Vt(e)?e.checked?`true`:`false`:e.value),e=r,e!==n&&(t.setValue(e),!0)}function Gt(e){if(e||=typeof document<`u`?document:void 0,e===void 0)return null;try{return e.activeElement||e.body}catch{return e.body}}var Kt=/[\n"\\]/g;function qt(e){return e.replace(Kt,function(e){return`\\`+e.charCodeAt(0).toString(16)+` `})}function Jt(e,t,n,r,i,a,o,s){e.name=``,o!=null&&typeof o!=`function`&&typeof o!=`symbol`&&typeof o!=`boolean`?e.type=o:e.removeAttribute(`type`),t==null?o!==`submit`&&o!==`reset`||e.removeAttribute(`value`):o===`number`?(t===0&&e.value===``||e.value!=t)&&(e.value=``+Bt(t)):e.value!==``+Bt(t)&&(e.value=``+Bt(t)),t==null?n==null?r!=null&&e.removeAttribute(`value`):Xt(e,o,Bt(n)):Xt(e,o,Bt(t)),i==null&&a!=null&&(e.defaultChecked=!!a),i!=null&&(e.checked=i&&typeof i!=`function`&&typeof i!=`symbol`),s!=null&&typeof s!=`function`&&typeof s!=`symbol`&&typeof s!=`boolean`?e.name=``+Bt(s):e.removeAttribute(`name`)}function Yt(e,t,n,r,i,a,o,s){if(a!=null&&typeof a!=`function`&&typeof a!=`symbol`&&typeof a!=`boolean`&&(e.type=a),t!=null||n!=null){if(!(a!==`submit`&&a!==`reset`||t!=null)){Ut(e);return}n=n==null?``:``+Bt(n),t=t==null?n:``+Bt(t),s||t===e.value||(e.value=t),e.defaultValue=t}r??=i,r=typeof r!=`function`&&typeof r!=`symbol`&&!!r,e.checked=s?e.checked:!!r,e.defaultChecked=!!r,o!=null&&typeof o!=`function`&&typeof o!=`symbol`&&typeof o!=`boolean`&&(e.name=o),Ut(e)}function Xt(e,t,n){t===`number`&&Gt(e.ownerDocument)===e||e.defaultValue===``+n||(e.defaultValue=``+n)}function Zt(e,t,n,r){if(e=e.options,t){t={};for(var i=0;i<n.length;i++)t[`$`+n[i]]=!0;for(n=0;n<e.length;n++)i=t.hasOwnProperty(`$`+e[n].value),e[n].selected!==i&&(e[n].selected=i),i&&r&&(e[n].defaultSelected=!0)}else{for(n=``+Bt(n),t=null,i=0;i<e.length;i++){if(e[i].value===n){e[i].selected=!0,r&&(e[i].defaultSelected=!0);return}t!==null||e[i].disabled||(t=e[i])}t!==null&&(t.selected=!0)}}function Qt(e,t,n){if(t!=null&&(t=``+Bt(t),t!==e.value&&(e.value=t),n==null)){e.defaultValue!==t&&(e.defaultValue=t);return}e.defaultValue=n==null?``:``+Bt(n)}function $t(e,t,n,r){if(t==null){if(r!=null){if(n!=null)throw Error(s(92));if(le(r)){if(1<r.length)throw Error(s(93));r=r[0]}n=r}n??=``,t=n}n=Bt(t),e.defaultValue=n,r=e.textContent,r===n&&r!==``&&r!==null&&(e.value=r),Ut(e)}function en(e,t){if(t){var n=e.firstChild;if(n&&n===e.lastChild&&n.nodeType===3){n.nodeValue=t;return}}e.textContent=t}var tn=new Set(`animationIterationCount aspectRatio borderImageOutset borderImageSlice borderImageWidth boxFlex boxFlexGroup boxOrdinalGroup columnCount columns flex flexGrow flexPositive flexShrink flexNegative flexOrder gridArea gridRow gridRowEnd gridRowSpan gridRowStart gridColumn gridColumnEnd gridColumnSpan gridColumnStart fontWeight lineClamp lineHeight opacity order orphans scale tabSize widows zIndex zoom fillOpacity floodOpacity stopOpacity strokeDasharray strokeDashoffset strokeMiterlimit strokeOpacity strokeWidth MozAnimationIterationCount MozBoxFlex MozBoxFlexGroup MozLineClamp msAnimationIterationCount msFlex msZoom msFlexGrow msFlexNegative msFlexOrder msFlexPositive msFlexShrink msGridColumn msGridColumnSpan msGridRow msGridRowSpan WebkitAnimationIterationCount WebkitBoxFlex WebKitBoxFlexGroup WebkitBoxOrdinalGroup WebkitColumnCount WebkitColumns WebkitFlex WebkitFlexGrow WebkitFlexPositive WebkitFlexShrink WebkitLineClamp`.split(` `));function nn(e,t,n){var r=t.indexOf(`--`)===0;n==null||typeof n==`boolean`||n===``?r?e.setProperty(t,``):t===`float`?e.cssFloat=``:e[t]=``:r?e.setProperty(t,n):typeof n!=`number`||n===0||tn.has(t)?t===`float`?e.cssFloat=n:e[t]=(``+n).trim():e[t]=n+`px`}function rn(e,t,n){if(t!=null&&typeof t!=`object`)throw Error(s(62));if(e=e.style,n!=null){for(var r in n)!n.hasOwnProperty(r)||t!=null&&t.hasOwnProperty(r)||(r.indexOf(`--`)===0?e.setProperty(r,``):r===`float`?e.cssFloat=``:e[r]=``);for(var i in t)r=t[i],t.hasOwnProperty(i)&&n[i]!==r&&nn(e,i,r)}else for(var a in t)t.hasOwnProperty(a)&&nn(e,a,t[a])}function an(e){if(e.indexOf(`-`)===-1)return!1;switch(e){case`annotation-xml`:case`color-profile`:case`font-face`:case`font-face-src`:case`font-face-uri`:case`font-face-format`:case`font-face-name`:case`missing-glyph`:return!1;default:return!0}}var on=new Map([[`acceptCharset`,`accept-charset`],[`htmlFor`,`for`],[`httpEquiv`,`http-equiv`],[`crossOrigin`,`crossorigin`],[`accentHeight`,`accent-height`],[`alignmentBaseline`,`alignment-baseline`],[`arabicForm`,`arabic-form`],[`baselineShift`,`baseline-shift`],[`capHeight`,`cap-height`],[`clipPath`,`clip-path`],[`clipRule`,`clip-rule`],[`colorInterpolation`,`color-interpolation`],[`colorInterpolationFilters`,`color-interpolation-filters`],[`colorProfile`,`color-profile`],[`colorRendering`,`color-rendering`],[`dominantBaseline`,`dominant-baseline`],[`enableBackground`,`enable-background`],[`fillOpacity`,`fill-opacity`],[`fillRule`,`fill-rule`],[`floodColor`,`flood-color`],[`floodOpacity`,`flood-opacity`],[`fontFamily`,`font-family`],[`fontSize`,`font-size`],[`fontSizeAdjust`,`font-size-adjust`],[`fontStretch`,`font-stretch`],[`fontStyle`,`font-style`],[`fontVariant`,`font-variant`],[`fontWeight`,`font-weight`],[`glyphName`,`glyph-name`],[`glyphOrientationHorizontal`,`glyph-orientation-horizontal`],[`glyphOrientationVertical`,`glyph-orientation-vertical`],[`horizAdvX`,`horiz-adv-x`],[`horizOriginX`,`horiz-origin-x`],[`imageRendering`,`image-rendering`],[`letterSpacing`,`letter-spacing`],[`lightingColor`,`lighting-color`],[`markerEnd`,`marker-end`],[`markerMid`,`marker-mid`],[`markerStart`,`marker-start`],[`overlinePosition`,`overline-position`],[`overlineThickness`,`overline-thickness`],[`paintOrder`,`paint-order`],[`panose-1`,`panose-1`],[`pointerEvents`,`pointer-events`],[`renderingIntent`,`rendering-intent`],[`shapeRendering`,`shape-rendering`],[`stopColor`,`stop-color`],[`stopOpacity`,`stop-opacity`],[`strikethroughPosition`,`strikethrough-position`],[`strikethroughThickness`,`strikethrough-thickness`],[`strokeDasharray`,`stroke-dasharray`],[`strokeDashoffset`,`stroke-dashoffset`],[`strokeLinecap`,`stroke-linecap`],[`strokeLinejoin`,`stroke-linejoin`],[`strokeMiterlimit`,`stroke-miterlimit`],[`strokeOpacity`,`stroke-opacity`],[`strokeWidth`,`stroke-width`],[`textAnchor`,`text-anchor`],[`textDecoration`,`text-decoration`],[`textRendering`,`text-rendering`],[`transformOrigin`,`transform-origin`],[`underlinePosition`,`underline-position`],[`underlineThickness`,`underline-thickness`],[`unicodeBidi`,`unicode-bidi`],[`unicodeRange`,`unicode-range`],[`unitsPerEm`,`units-per-em`],[`vAlphabetic`,`v-alphabetic`],[`vHanging`,`v-hanging`],[`vIdeographic`,`v-ideographic`],[`vMathematical`,`v-mathematical`],[`vectorEffect`,`vector-effect`],[`vertAdvY`,`vert-adv-y`],[`vertOriginX`,`vert-origin-x`],[`vertOriginY`,`vert-origin-y`],[`wordSpacing`,`word-spacing`],[`writingMode`,`writing-mode`],[`xmlnsXlink`,`xmlns:xlink`],[`xHeight`,`x-height`]]),sn=/^[\u0000-\u001F ]*j[\r\n\t]*a[\r\n\t]*v[\r\n\t]*a[\r\n\t]*s[\r\n\t]*c[\r\n\t]*r[\r\n\t]*i[\r\n\t]*p[\r\n\t]*t[\r\n\t]*:/i;function cn(e){return sn.test(``+e)?`javascript:throw new Error('React has blocked a javascript: URL as a security precaution.')`:e}function ln(){}var un=null;function dn(e){return e=e.target||e.srcElement||window,e.correspondingUseElement&&(e=e.correspondingUseElement),e.nodeType===3?e.parentNode:e}var fn=null,pn=null;function mn(e){var t=Et(e);if(t&&(e=t.stateNode)){var n=e[_t]||null;a:switch(e=t.stateNode,t.type){case`input`:if(Jt(e,n.value,n.defaultValue,n.defaultValue,n.checked,n.defaultChecked,n.type,n.name),t=n.name,n.type===`radio`&&t!=null){for(n=e;n.parentNode;)n=n.parentNode;for(n=n.querySelectorAll(`input[name="`+qt(``+t)+`"][type="radio"]`),t=0;t<n.length;t++){var r=n[t];if(r!==e&&r.form===e.form){var i=r[_t]||null;if(!i)throw Error(s(90));Jt(r,i.value,i.defaultValue,i.defaultValue,i.checked,i.defaultChecked,i.type,i.name)}}for(t=0;t<n.length;t++)r=n[t],r.form===e.form&&Wt(r)}break a;case`textarea`:Qt(e,n.value,n.defaultValue);break a;case`select`:t=n.value,t!=null&&Zt(e,!!n.multiple,t,!1)}}}var hn=!1;function gn(e,t,n){if(hn)return e(t,n);hn=!0;try{return e(t)}finally{if(hn=!1,(fn!==null||pn!==null)&&(bu(),fn&&(t=fn,e=pn,pn=fn=null,mn(t),e)))for(t=0;t<e.length;t++)mn(e[t])}}function _n(e,t){var n=e.stateNode;if(n===null)return null;var r=n[_t]||null;if(r===null)return null;n=r[t];a:switch(t){case`onClick`:case`onClickCapture`:case`onDoubleClick`:case`onDoubleClickCapture`:case`onMouseDown`:case`onMouseDownCapture`:case`onMouseMove`:case`onMouseMoveCapture`:case`onMouseUp`:case`onMouseUpCapture`:case`onMouseEnter`:(r=!r.disabled)||(e=e.type,r=e!==`button`&&e!==`input`&&e!==`select`&&e!==`textarea`),e=!r;break a;default:e=!1}if(e)return null;if(n&&typeof n!=`function`)throw Error(s(231,t,typeof n));return n}var vn=!(typeof window>`u`||window.document===void 0||window.document.createElement===void 0),yn=!1;if(vn)try{var bn={};Object.defineProperty(bn,"passive",{get:function(){yn=!0}}),window.addEventListener(`test`,bn,bn),window.removeEventListener(`test`,bn,bn)}catch{yn=!1}var xn=null,Sn=null,Cn=null;function wn(){if(Cn)return Cn;var e,t=Sn,n=t.length,r,i=`value`in xn?xn.value:xn.textContent,a=i.length;for(e=0;e<n&&t[e]===i[e];e++);var o=n-e;for(r=1;r<=o&&t[n-r]===i[a-r];r++);return Cn=i.slice(e,1<r?1-r:void 0)}function Tn(e){var t=e.keyCode;return`charCode`in e?(e=e.charCode,e===0&&t===13&&(e=13)):e=t,e===10&&(e=13),32<=e||e===13?e:0}function En(){return!0}function Dn(){return!1}function On(e){function t(t,n,r,i,a){for(var o in this._reactName=t,this._targetInst=r,this.type=n,this.nativeEvent=i,this.target=a,this.currentTarget=null,e)e.hasOwnProperty(o)&&(t=e[o],this[o]=t?t(i):i[o]);return this.isDefaultPrevented=(i.defaultPrevented==null?!1===i.returnValue:i.defaultPrevented)?En:Dn,this.isPropagationStopped=Dn,this}return h(t.prototype,{preventDefault:function(){this.defaultPrevented=!0;var e=this.nativeEvent;e&&(e.preventDefault?e.preventDefault():typeof e.returnValue!=`unknown`&&(e.returnValue=!1),this.isDefaultPrevented=En)},stopPropagation:function(){var e=this.nativeEvent;e&&(e.stopPropagation?e.stopPropagation():typeof e.cancelBubble!=`unknown`&&(e.cancelBubble=!0),this.isPropagationStopped=En)},persist:function(){},isPersistent:En}),t}var kn={eventPhase:0,bubbles:0,cancelable:0,timeStamp:function(e){return e.timeStamp||Date.now()},defaultPrevented:0,isTrusted:0},An=On(kn),jn=h({},kn,{view:0,detail:0}),Mn=On(jn),Nn,Pn,Fn,In=h({},jn,{screenX:0,screenY:0,clientX:0,clientY:0,pageX:0,pageY:0,ctrlKey:0,shiftKey:0,altKey:0,metaKey:0,getModifierState:qn,button:0,buttons:0,relatedTarget:function(e){return e.relatedTarget===void 0?e.fromElement===e.srcElement?e.toElement:e.fromElement:e.relatedTarget},movementX:function(e){return`movementX`in e?e.movementX:(e!==Fn&&(Fn&&e.type===`mousemove`?(Nn=e.screenX-Fn.screenX,Pn=e.screenY-Fn.screenY):Pn=Nn=0,Fn=e),Nn)},movementY:function(e){return`movementY`in e?e.movementY:Pn}}),Ln=On(In),Rn=On(h({},In,{dataTransfer:0})),zn=On(h({},jn,{relatedTarget:0})),Bn=On(h({},kn,{animationName:0,elapsedTime:0,pseudoElement:0})),Vn=On(h({},kn,{clipboardData:function(e){return`clipboardData`in e?e.clipboardData:window.clipboardData}})),Hn=On(h({},kn,{data:0})),Un={Esc:`Escape`,Spacebar:` `,Left:`ArrowLeft`,Up:`ArrowUp`,Right:`ArrowRight`,Down:`ArrowDown`,Del:`Delete`,Win:`OS`,Menu:`ContextMenu`,Apps:`ContextMenu`,Scroll:`ScrollLock`,MozPrintableKey:`Unidentified`},Wn={8:`Backspace`,9:`Tab`,12:`Clear`,13:`Enter`,16:`Shift`,17:`Control`,18:`Alt`,19:`Pause`,20:`CapsLock`,27:`Escape`,32:` `,33:`PageUp`,34:`PageDown`,35:`End`,36:`Home`,37:`ArrowLeft`,38:`ArrowUp`,39:`ArrowRight`,40:`ArrowDown`,45:`Insert`,46:`Delete`,112:`F1`,113:`F2`,114:`F3`,115:`F4`,116:`F5`,117:`F6`,118:`F7`,119:`F8`,120:`F9`,121:`F10`,122:`F11`,123:`F12`,144:`NumLock`,145:`ScrollLock`,224:`Meta`},Gn={Alt:`altKey`,Control:`ctrlKey`,Meta:`metaKey`,Shift:`shiftKey`};function Kn(e){var t=this.nativeEvent;return t.getModifierState?t.getModifierState(e):(e=Gn[e])?!!t[e]:!1}function qn(){return Kn}var Jn=On(h({},jn,{key:function(e){if(e.key){var t=Un[e.key]||e.key;if(t!==`Unidentified`)return t}return e.type===`keypress`?(e=Tn(e),e===13?`Enter`:String.fromCharCode(e)):e.type===`keydown`||e.type===`keyup`?Wn[e.keyCode]||`Unidentified`:``},code:0,location:0,ctrlKey:0,shiftKey:0,altKey:0,metaKey:0,repeat:0,locale:0,getModifierState:qn,charCode:function(e){return e.type===`keypress`?Tn(e):0},keyCode:function(e){return e.type===`keydown`||e.type===`keyup`?e.keyCode:0},which:function(e){return e.type===`keypress`?Tn(e):e.type===`keydown`||e.type===`keyup`?e.keyCode:0}})),Yn=On(h({},In,{pointerId:0,width:0,height:0,pressure:0,tangentialPressure:0,tiltX:0,tiltY:0,twist:0,pointerType:0,isPrimary:0})),Xn=On(h({},jn,{touches:0,targetTouches:0,changedTouches:0,altKey:0,metaKey:0,ctrlKey:0,shiftKey:0,getModifierState:qn})),Zn=On(h({},kn,{propertyName:0,elapsedTime:0,pseudoElement:0})),Qn=On(h({},In,{deltaX:function(e){return`deltaX`in e?e.deltaX:`wheelDeltaX`in e?-e.wheelDeltaX:0},deltaY:function(e){return`deltaY`in e?e.deltaY:`wheelDeltaY`in e?-e.wheelDeltaY:`wheelDelta`in e?-e.wheelDelta:0},deltaZ:0,deltaMode:0})),$n=On(h({},kn,{newState:0,oldState:0})),er=[9,13,27,32],tr=vn&&`CompositionEvent`in window,nr=null;vn&&`documentMode`in document&&(nr=document.documentMode);var rr=vn&&`TextEvent`in window&&!nr,ir=vn&&(!tr||nr&&8<nr&&11>=nr),ar=` `,or=!1;function sr(e,t){switch(e){case`keyup`:return er.indexOf(t.keyCode)!==-1;case`keydown`:return t.keyCode!==229;case`keypress`:case`mousedown`:case`focusout`:return!0;default:return!1}}function cr(e){return e=e.detail,typeof e==`object`&&`data`in e?e.data:null}var lr=!1;function ur(e,t){switch(e){case`compositionend`:return cr(t);case`keypress`:return t.which===32?(or=!0,ar):null;case`textInput`:return e=t.data,e===ar&&or?null:e;default:return null}}function dr(e,t){if(lr)return e===`compositionend`||!tr&&sr(e,t)?(e=wn(),Cn=Sn=xn=null,lr=!1,e):null;switch(e){case`paste`:return null;case`keypress`:if(!(t.ctrlKey||t.altKey||t.metaKey)||t.ctrlKey&&t.altKey){if(t.char&&1<t.char.length)return t.char;if(t.which)return String.fromCharCode(t.which)}return null;case`compositionend`:return ir&&t.locale!==`ko`?null:t.data;default:return null}}var fr={color:!0,date:!0,datetime:!0,"datetime-local":!0,email:!0,month:!0,number:!0,password:!0,range:!0,search:!0,tel:!0,text:!0,time:!0,url:!0,week:!0};function pr(e){var t=e&&e.nodeName&&e.nodeName.toLowerCase();return t===`input`?!!fr[e.type]:t===`textarea`}function mr(e,t,n,r){fn?pn?pn.push(r):pn=[r]:fn=r,t=Ed(t,`onChange`),0<t.length&&(n=new An(`onChange`,`change`,null,n,r),e.push({event:n,listeners:t}))}var hr=null,gr=null;function _r(e){yd(e,0)}function vr(e){if(Wt(Dt(e)))return e}function yr(e,t){if(e===`change`)return t}var br=!1;if(vn){var xr;if(vn){var Sr=`oninput`in document;if(!Sr){var Cr=document.createElement(`div`);Cr.setAttribute(`oninput`,`return;`),Sr=typeof Cr.oninput==`function`}xr=Sr}else xr=!1;br=xr&&(!document.documentMode||9<document.documentMode)}function wr(){hr&&(hr.detachEvent(`onpropertychange`,Tr),gr=hr=null)}function Tr(e){if(e.propertyName===`value`&&vr(gr)){var t=[];mr(t,gr,e,dn(e)),gn(_r,t)}}function Er(e,t,n){e===`focusin`?(wr(),hr=t,gr=n,hr.attachEvent(`onpropertychange`,Tr)):e===`focusout`&&wr()}function Dr(e){if(e===`selectionchange`||e===`keyup`||e===`keydown`)return vr(gr)}function Or(e,t){if(e===`click`)return vr(t)}function kr(e,t){if(e===`input`||e===`change`)return vr(t)}function Ar(e,t){return e===t&&(e!==0||1/e==1/t)||e!==e&&t!==t}var jr=typeof Object.is==`function`?Object.is:Ar;function Mr(e,t){if(jr(e,t))return!0;if(typeof e!=`object`||!e||typeof t!=`object`||!t)return!1;var n=Object.keys(e),r=Object.keys(t);if(n.length!==r.length)return!1;for(r=0;r<n.length;r++){var i=n[r];if(!Ae.call(t,i)||!jr(e[i],t[i]))return!1}return!0}function Nr(e){for(;e&&e.firstChild;)e=e.firstChild;return e}function Pr(e,t){var n=Nr(e);e=0;for(var r;n;){if(n.nodeType===3){if(r=e+n.textContent.length,e<=t&&r>=t)return{node:n,offset:t-e};e=r}a:{for(;n;){if(n.nextSibling){n=n.nextSibling;break a}n=n.parentNode}n=void 0}n=Nr(n)}}function Fr(e,t){return e&&t?e===t?!0:e&&e.nodeType===3?!1:t&&t.nodeType===3?Fr(e,t.parentNode):`contains`in e?e.contains(t):e.compareDocumentPosition?!!(e.compareDocumentPosition(t)&16):!1:!1}function Ir(e){e=e!=null&&e.ownerDocument!=null&&e.ownerDocument.defaultView!=null?e.ownerDocument.defaultView:window;for(var t=Gt(e.document);t instanceof e.HTMLIFrameElement;){try{var n=typeof t.contentWindow.location.href==`string`}catch{n=!1}if(n)e=t.contentWindow;else break;t=Gt(e.document)}return t}function Lr(e){var t=e&&e.nodeName&&e.nodeName.toLowerCase();return t&&(t===`input`&&(e.type===`text`||e.type===`search`||e.type===`tel`||e.type===`url`||e.type===`password`)||t===`textarea`||e.contentEditable===`true`)}var Rr=vn&&`documentMode`in document&&11>=document.documentMode,zr=null,Br=null,Vr=null,Hr=!1;function Ur(e,t,n){var r=n.window===n?n.document:n.nodeType===9?n:n.ownerDocument;Hr||zr==null||zr!==Gt(r)||(r=zr,`selectionStart`in r&&Lr(r)?r={start:r.selectionStart,end:r.selectionEnd}:(r=(r.ownerDocument&&r.ownerDocument.defaultView||window).getSelection(),r={anchorNode:r.anchorNode,anchorOffset:r.anchorOffset,focusNode:r.focusNode,focusOffset:r.focusOffset}),Vr&&Mr(Vr,r)||(Vr=r,r=Ed(Br,`onSelect`),0<r.length&&(t=new An(`onSelect`,`select`,null,t,n),e.push({event:t,listeners:r}),t.target=zr)))}function Wr(e,t){var n={};return n[e.toLowerCase()]=t.toLowerCase(),n[`Webkit`+e]=`webkit`+t,n[`Moz`+e]=`moz`+t,n}var Gr={animationend:Wr(`Animation`,`AnimationEnd`),animationiteration:Wr(`Animation`,`AnimationIteration`),animationstart:Wr(`Animation`,`AnimationStart`),transitionrun:Wr(`Transition`,`TransitionRun`),transitionstart:Wr(`Transition`,`TransitionStart`),transitioncancel:Wr(`Transition`,`TransitionCancel`),transitionend:Wr(`Transition`,`TransitionEnd`)},Kr={},qr={};vn&&(qr=document.createElement(`div`).style,`AnimationEvent`in window||(delete Gr.animationend.animation,delete Gr.animationiteration.animation,delete Gr.animationstart.animation),`TransitionEvent`in window||delete Gr.transitionend.transition);function Jr(e){if(Kr[e])return Kr[e];if(!Gr[e])return e;var t=Gr[e],n;for(n in t)if(t.hasOwnProperty(n)&&n in qr)return Kr[e]=t[n];return e}var Yr=Jr(`animationend`),Xr=Jr(`animationiteration`),Zr=Jr(`animationstart`),Qr=Jr(`transitionrun`),$r=Jr(`transitionstart`),ei=Jr(`transitioncancel`),ti=Jr(`transitionend`),ni=new Map,ri=`abort auxClick beforeToggle cancel canPlay canPlayThrough click close contextMenu copy cut drag dragEnd dragEnter dragExit dragLeave dragOver dragStart drop durationChange emptied encrypted ended error gotPointerCapture input invalid keyDown keyPress keyUp load loadedData loadedMetadata loadStart lostPointerCapture mouseDown mouseMove mouseOut mouseOver mouseUp paste pause play playing pointerCancel pointerDown pointerMove pointerOut pointerOver pointerUp progress rateChange reset resize seeked seeking stalled submit suspend timeUpdate touchCancel touchEnd touchStart volumeChange scroll toggle touchMove waiting wheel`.split(` `);ri.push(`scrollEnd`);function ii(e,t){ni.set(e,t),jt(t,[e])}var ai=typeof reportError==`function`?reportError:function(e){if(typeof window==`object`&&typeof window.ErrorEvent==`function`){var t=new window.ErrorEvent(`error`,{bubbles:!0,cancelable:!0,message:typeof e==`object`&&e&&typeof e.message==`string`?String(e.message):String(e),error:e});if(!window.dispatchEvent(t))return}else if(typeof process==`object`&&typeof process.emit==`function`){process.emit(`uncaughtException`,e);return}console.error(e)},oi=[],si=0,ci=0;function li(){for(var e=si,t=ci=si=0;t<e;){var n=oi[t];oi[t++]=null;var r=oi[t];oi[t++]=null;var i=oi[t];oi[t++]=null;var a=oi[t];if(oi[t++]=null,r!==null&&i!==null){var o=r.pending;o===null?i.next=i:(i.next=o.next,o.next=i),r.pending=i}a!==0&&pi(n,i,a)}}function ui(e,t,n,r){oi[si++]=e,oi[si++]=t,oi[si++]=n,oi[si++]=r,ci|=r,e.lanes|=r,e=e.alternate,e!==null&&(e.lanes|=r)}function di(e,t,n,r){return ui(e,t,n,r),mi(e)}function fi(e,t){return ui(e,null,null,t),mi(e)}function pi(e,t,n){e.lanes|=n;var r=e.alternate;r!==null&&(r.lanes|=n);for(var i=!1,a=e.return;a!==null;)a.childLanes|=n,r=a.alternate,r!==null&&(r.childLanes|=n),a.tag===22&&(e=a.stateNode,e===null||e._visibility&1||(i=!0)),e=a,a=a.return;return e.tag===3?(a=e.stateNode,i&&t!==null&&(i=31-qe(n),e=a.hiddenUpdates,r=e[i],r===null?e[i]=[t]:r.push(t),t.lane=n|536870912),a):null}function mi(e){if(50<du)throw du=0,fu=null,Error(s(185));for(var t=e.return;t!==null;)e=t,t=e.return;return e.tag===3?e.stateNode:null}var hi={};function gi(e,t,n,r){this.tag=e,this.key=n,this.sibling=this.child=this.return=this.stateNode=this.type=this.elementType=null,this.index=0,this.refCleanup=this.ref=null,this.pendingProps=t,this.dependencies=this.memoizedState=this.updateQueue=this.memoizedProps=null,this.mode=r,this.subtreeFlags=this.flags=0,this.deletions=null,this.childLanes=this.lanes=0,this.alternate=null}function _i(e,t,n,r){return new gi(e,t,n,r)}function vi(e){return e=e.prototype,!(!e||!e.isReactComponent)}function yi(e,t){var n=e.alternate;return n===null?(n=_i(e.tag,t,e.key,e.mode),n.elementType=e.elementType,n.type=e.type,n.stateNode=e.stateNode,n.alternate=e,e.alternate=n):(n.pendingProps=t,n.type=e.type,n.flags=0,n.subtreeFlags=0,n.deletions=null),n.flags=e.flags&65011712,n.childLanes=e.childLanes,n.lanes=e.lanes,n.child=e.child,n.memoizedProps=e.memoizedProps,n.memoizedState=e.memoizedState,n.updateQueue=e.updateQueue,t=e.dependencies,n.dependencies=t===null?null:{lanes:t.lanes,firstContext:t.firstContext},n.sibling=e.sibling,n.index=e.index,n.ref=e.ref,n.refCleanup=e.refCleanup,n}function bi(e,t){e.flags&=65011714;var n=e.alternate;return n===null?(e.childLanes=0,e.lanes=t,e.child=null,e.subtreeFlags=0,e.memoizedProps=null,e.memoizedState=null,e.updateQueue=null,e.dependencies=null,e.stateNode=null):(e.childLanes=n.childLanes,e.lanes=n.lanes,e.child=n.child,e.subtreeFlags=0,e.deletions=null,e.memoizedProps=n.memoizedProps,e.memoizedState=n.memoizedState,e.updateQueue=n.updateQueue,e.type=n.type,t=n.dependencies,e.dependencies=t===null?null:{lanes:t.lanes,firstContext:t.firstContext}),e}function xi(e,t,n,r,i,a){var o=0;if(r=e,typeof e==`function`)vi(e)&&(o=1);else if(typeof e==`string`)o=Uf(e,n,he.current)?26:e===`html`||e===`head`||e===`body`?27:5;else a:switch(e){case re:return e=_i(31,n,t,i),e.elementType=re,e.lanes=a,e;case y:return Si(n.children,i,a,t);case b:o=8,i|=24;break;case x:return e=_i(12,n,t,i|2),e.elementType=x,e.lanes=a,e;case ee:return e=_i(13,n,t,i),e.elementType=ee,e.lanes=a,e;case te:return e=_i(19,n,t,i),e.elementType=te,e.lanes=a,e;default:if(typeof e==`object`&&e)switch(e.$$typeof){case C:o=10;break a;case S:o=9;break a;case w:o=11;break a;case ne:o=14;break a;case T:o=16,r=null;break a}o=29,n=Error(s(130,e===null?`null`:typeof e,``)),r=null}return t=_i(o,n,t,i),t.elementType=e,t.type=r,t.lanes=a,t}function Si(e,t,n,r){return e=_i(7,e,r,t),e.lanes=n,e}function Ci(e,t,n){return e=_i(6,e,null,t),e.lanes=n,e}function wi(e){var t=_i(18,null,null,0);return t.stateNode=e,t}function Ti(e,t,n){return t=_i(4,e.children===null?[]:e.children,e.key,t),t.lanes=n,t.stateNode={containerInfo:e.containerInfo,pendingChildren:null,implementation:e.implementation},t}var Ei=new WeakMap;function Di(e,t){if(typeof e==`object`&&e){var n=Ei.get(e);return n===void 0?(t={value:e,source:t,stack:ke(t)},Ei.set(e,t),t):n}return{value:e,source:t,stack:ke(t)}}var Oi=[],ki=0,Ai=null,ji=0,Mi=[],Ni=0,Pi=null,Fi=1,Ii=``;function Li(e,t){Oi[ki++]=ji,Oi[ki++]=Ai,Ai=e,ji=t}function Ri(e,t,n){Mi[Ni++]=Fi,Mi[Ni++]=Ii,Mi[Ni++]=Pi,Pi=e;var r=Fi;e=Ii;var i=32-qe(r)-1;r&=~(1<<i),n+=1;var a=32-qe(t)+i;if(30<a){var o=i-i%5;a=(r&(1<<o)-1).toString(32),r>>=o,i-=o,Fi=1<<32-qe(t)+i|n<<i|r,Ii=a+e}else Fi=1<<a|n<<i|r,Ii=e}function zi(e){e.return!==null&&(Li(e,1),Ri(e,1,0))}function Bi(e){for(;e===Ai;)Ai=Oi[--ki],Oi[ki]=null,ji=Oi[--ki],Oi[ki]=null;for(;e===Pi;)Pi=Mi[--Ni],Mi[Ni]=null,Ii=Mi[--Ni],Mi[Ni]=null,Fi=Mi[--Ni],Mi[Ni]=null}function Vi(e,t){Mi[Ni++]=Fi,Mi[Ni++]=Ii,Mi[Ni++]=Pi,Fi=t.id,Ii=t.overflow,Pi=e}var Hi=null,A=null,j=!1,Ui=null,Wi=!1,Gi=Error(s(519));function Ki(e){throw Qi(Di(Error(s(418,1<arguments.length&&arguments[1]!==void 0&&arguments[1]?`text`:`HTML`,``)),e)),Gi}function qi(e){var t=e.stateNode,n=e.type,r=e.memoizedProps;switch(t[gt]=e,t[_t]=r,n){case`dialog`:Q(`cancel`,t),Q(`close`,t);break;case`iframe`:case`object`:case`embed`:Q(`load`,t);break;case`video`:case`audio`:for(n=0;n<_d.length;n++)Q(_d[n],t);break;case`source`:Q(`error`,t);break;case`img`:case`image`:case`link`:Q(`error`,t),Q(`load`,t);break;case`details`:Q(`toggle`,t);break;case`input`:Q(`invalid`,t),Yt(t,r.value,r.defaultValue,r.checked,r.defaultChecked,r.type,r.name,!0);break;case`select`:Q(`invalid`,t);break;case`textarea`:Q(`invalid`,t),$t(t,r.value,r.defaultValue,r.children)}n=r.children,typeof n!=`string`&&typeof n!=`number`&&typeof n!=`bigint`||t.textContent===``+n||!0===r.suppressHydrationWarning||Md(t.textContent,n)?(r.popover!=null&&(Q(`beforetoggle`,t),Q(`toggle`,t)),r.onScroll!=null&&Q(`scroll`,t),r.onScrollEnd!=null&&Q(`scrollend`,t),r.onClick!=null&&(t.onclick=ln),t=!0):t=!1,t||Ki(e,!0)}function Ji(e){for(Hi=e.return;Hi;)switch(Hi.tag){case 5:case 31:case 13:Wi=!1;return;case 27:case 3:Wi=!0;return;default:Hi=Hi.return}}function Yi(e){if(e!==Hi)return!1;if(!j)return Ji(e),j=!0,!1;var t=e.tag,n;if((n=t!==3&&t!==27)&&((n=t===5)&&(n=e.type,n=n===`form`||n===`button`||Ud(e.type,e.memoizedProps)),n=!n),n&&A&&Ki(e),Ji(e),t===13){if(e=e.memoizedState,e=e===null?null:e.dehydrated,!e)throw Error(s(317));A=uf(e)}else if(t===31){if(e=e.memoizedState,e=e===null?null:e.dehydrated,!e)throw Error(s(317));A=uf(e)}else t===27?(t=A,Zd(e.type)?(e=lf,lf=null,A=e):A=t):A=Hi?cf(e.stateNode.nextSibling):null;return!0}function Xi(){A=Hi=null,j=!1}function Zi(){var e=Ui;return e!==null&&(Ql===null?Ql=e:Ql.push.apply(Ql,e),Ui=null),e}function Qi(e){Ui===null?Ui=[e]:Ui.push(e)}var $i=pe(null),ea=null,ta=null;function na(e,t,n){O($i,t._currentValue),t._currentValue=n}function ra(e){e._currentValue=$i.current,me($i)}function ia(e,t,n){for(;e!==null;){var r=e.alternate;if((e.childLanes&t)===t?r!==null&&(r.childLanes&t)!==t&&(r.childLanes|=t):(e.childLanes|=t,r!==null&&(r.childLanes|=t)),e===n)break;e=e.return}}function aa(e,t,n,r){var i=e.child;for(i!==null&&(i.return=e);i!==null;){var a=i.dependencies;if(a!==null){var o=i.child;a=a.firstContext;a:for(;a!==null;){var c=a;a=i;for(var l=0;l<t.length;l++)if(c.context===t[l]){a.lanes|=n,c=a.alternate,c!==null&&(c.lanes|=n),ia(a.return,n,e),r||(o=null);break a}a=c.next}}else if(i.tag===18){if(o=i.return,o===null)throw Error(s(341));o.lanes|=n,a=o.alternate,a!==null&&(a.lanes|=n),ia(o,n,e),o=null}else o=i.child;if(o!==null)o.return=i;else for(o=i;o!==null;){if(o===e){o=null;break}if(i=o.sibling,i!==null){i.return=o.return,o=i;break}o=o.return}i=o}}function oa(e,t,n,r){e=null;for(var i=t,a=!1;i!==null;){if(!a){if(i.flags&524288)a=!0;else if(i.flags&262144)break}if(i.tag===10){var o=i.alternate;if(o===null)throw Error(s(387));if(o=o.memoizedProps,o!==null){var c=i.type;jr(i.pendingProps.value,o.value)||(e===null?e=[c]:e.push(c))}}else if(i===ve.current){if(o=i.alternate,o===null)throw Error(s(387));o.memoizedState.memoizedState!==i.memoizedState.memoizedState&&(e===null?e=[Qf]:e.push(Qf))}i=i.return}e!==null&&aa(t,e,n,r),t.flags|=262144}function sa(e){for(e=e.firstContext;e!==null;){if(!jr(e.context._currentValue,e.memoizedValue))return!0;e=e.next}return!1}function ca(e){ea=e,ta=null,e=e.dependencies,e!==null&&(e.firstContext=null)}function la(e){return da(ea,e)}function ua(e,t){return ea===null&&ca(e),da(e,t)}function da(e,t){var n=t._currentValue;if(t={context:t,memoizedValue:n,next:null},ta===null){if(e===null)throw Error(s(308));ta=t,e.dependencies={lanes:0,firstContext:t},e.flags|=524288}else ta=ta.next=t;return n}var fa=typeof AbortController<`u`?AbortController:function(){var e=[],t=this.signal={aborted:!1,addEventListener:function(t,n){e.push(n)}};this.abort=function(){t.aborted=!0,e.forEach(function(e){return e()})}},pa=t.unstable_scheduleCallback,ma=t.unstable_NormalPriority,M={$$typeof:C,Consumer:null,Provider:null,_currentValue:null,_currentValue2:null,_threadCount:0};function ha(){return{controller:new fa,data:new Map,refCount:0}}function ga(e){e.refCount--,e.refCount===0&&pa(ma,function(){e.controller.abort()})}var _a=null,va=0,ya=0,ba=null;function xa(e,t){if(_a===null){var n=_a=[];va=0,ya=dd(),ba={status:`pending`,value:void 0,then:function(e){n.push(e)}}}return va++,t.then(Sa,Sa),t}function Sa(){if(--va===0&&_a!==null){ba!==null&&(ba.status=`fulfilled`);var e=_a;_a=null,ya=0,ba=null;for(var t=0;t<e.length;t++)(0,e[t])()}}function Ca(e,t){var n=[],r={status:`pending`,value:null,reason:null,then:function(e){n.push(e)}};return e.then(function(){r.status=`fulfilled`,r.value=t;for(var e=0;e<n.length;e++)(0,n[e])(t)},function(e){for(r.status=`rejected`,r.reason=e,e=0;e<n.length;e++)(0,n[e])(void 0)}),r}var wa=E.S;E.S=function(e,t){tu=Fe(),typeof t==`object`&&t&&typeof t.then==`function`&&xa(e,t),wa!==null&&wa(e,t)};var Ta=pe(null);function Ea(){var e=Ta.current;return e===null?G.pooledCache:e}function Da(e,t){t===null?O(Ta,Ta.current):O(Ta,t.pool)}function Oa(){var e=Ea();return e===null?null:{parent:M._currentValue,pool:e}}var ka=Error(s(460)),Aa=Error(s(474)),ja=Error(s(542)),Ma={then:function(){}};function Na(e){return e=e.status,e===`fulfilled`||e===`rejected`}function Pa(e,t,n){switch(n=e[n],n===void 0?e.push(t):n!==t&&(t.then(ln,ln),t=n),t.status){case`fulfilled`:return t.value;case`rejected`:throw e=t.reason,Ra(e),e;default:if(typeof t.status==`string`)t.then(ln,ln);else{if(e=G,e!==null&&100<e.shellSuspendCounter)throw Error(s(482));e=t,e.status=`pending`,e.then(function(e){if(t.status===`pending`){var n=t;n.status=`fulfilled`,n.value=e}},function(e){if(t.status===`pending`){var n=t;n.status=`rejected`,n.reason=e}})}switch(t.status){case`fulfilled`:return t.value;case`rejected`:throw e=t.reason,Ra(e),e}throw Ia=t,ka}}function Fa(e){try{var t=e._init;return t(e._payload)}catch(e){throw typeof e==`object`&&e&&typeof e.then==`function`?(Ia=e,ka):e}}var Ia=null;function La(){if(Ia===null)throw Error(s(459));var e=Ia;return Ia=null,e}function Ra(e){if(e===ka||e===ja)throw Error(s(483))}var za=null,Ba=0;function Va(e){var t=Ba;return Ba+=1,za===null&&(za=[]),Pa(za,e,t)}function Ha(e,t){t=t.props.ref,e.ref=t===void 0?null:t}function Ua(e,t){throw t.$$typeof===g?Error(s(525)):(e=Object.prototype.toString.call(t),Error(s(31,e===`[object Object]`?`object with keys {`+Object.keys(t).join(`, `)+`}`:e)))}function Wa(e){function t(t,n){if(e){var r=t.deletions;r===null?(t.deletions=[n],t.flags|=16):r.push(n)}}function n(n,r){if(!e)return null;for(;r!==null;)t(n,r),r=r.sibling;return null}function r(e){for(var t=new Map;e!==null;)e.key===null?t.set(e.index,e):t.set(e.key,e),e=e.sibling;return t}function i(e,t){return e=yi(e,t),e.index=0,e.sibling=null,e}function a(t,n,r){return t.index=r,e?(r=t.alternate,r===null?(t.flags|=67108866,n):(r=r.index,r<n?(t.flags|=67108866,n):r)):(t.flags|=1048576,n)}function o(t){return e&&t.alternate===null&&(t.flags|=67108866),t}function c(e,t,n,r){return t===null||t.tag!==6?(t=Ci(n,e.mode,r),t.return=e,t):(t=i(t,n),t.return=e,t)}function l(e,t,n,r){var a=n.type;return a===y?d(e,t,n.props.children,r,n.key):t!==null&&(t.elementType===a||typeof a==`object`&&a&&a.$$typeof===T&&Fa(a)===t.type)?(t=i(t,n.props),Ha(t,n),t.return=e,t):(t=xi(n.type,n.key,n.props,null,e.mode,r),Ha(t,n),t.return=e,t)}function u(e,t,n,r){return t===null||t.tag!==4||t.stateNode.containerInfo!==n.containerInfo||t.stateNode.implementation!==n.implementation?(t=Ti(n,e.mode,r),t.return=e,t):(t=i(t,n.children||[]),t.return=e,t)}function d(e,t,n,r,a){return t===null||t.tag!==7?(t=Si(n,e.mode,r,a),t.return=e,t):(t=i(t,n),t.return=e,t)}function f(e,t,n){if(typeof t==`string`&&t!==``||typeof t==`number`||typeof t==`bigint`)return t=Ci(``+t,e.mode,n),t.return=e,t;if(typeof t==`object`&&t){switch(t.$$typeof){case _:return n=xi(t.type,t.key,t.props,null,e.mode,n),Ha(n,t),n.return=e,n;case v:return t=Ti(t,e.mode,n),t.return=e,t;case T:return t=Fa(t),f(e,t,n)}if(le(t)||oe(t))return t=Si(t,e.mode,n,null),t.return=e,t;if(typeof t.then==`function`)return f(e,Va(t),n);if(t.$$typeof===C)return f(e,ua(e,t),n);Ua(e,t)}return null}function p(e,t,n,r){var i=t===null?null:t.key;if(typeof n==`string`&&n!==``||typeof n==`number`||typeof n==`bigint`)return i===null?c(e,t,``+n,r):null;if(typeof n==`object`&&n){switch(n.$$typeof){case _:return n.key===i?l(e,t,n,r):null;case v:return n.key===i?u(e,t,n,r):null;case T:return n=Fa(n),p(e,t,n,r)}if(le(n)||oe(n))return i===null?d(e,t,n,r,null):null;if(typeof n.then==`function`)return p(e,t,Va(n),r);if(n.$$typeof===C)return p(e,t,ua(e,n),r);Ua(e,n)}return null}function m(e,t,n,r,i){if(typeof r==`string`&&r!==``||typeof r==`number`||typeof r==`bigint`)return e=e.get(n)||null,c(t,e,``+r,i);if(typeof r==`object`&&r){switch(r.$$typeof){case _:return e=e.get(r.key===null?n:r.key)||null,l(t,e,r,i);case v:return e=e.get(r.key===null?n:r.key)||null,u(t,e,r,i);case T:return r=Fa(r),m(e,t,n,r,i)}if(le(r)||oe(r))return e=e.get(n)||null,d(t,e,r,i,null);if(typeof r.then==`function`)return m(e,t,n,Va(r),i);if(r.$$typeof===C)return m(e,t,n,ua(t,r),i);Ua(t,r)}return null}function h(i,o,s,c){for(var l=null,u=null,d=o,h=o=0,g=null;d!==null&&h<s.length;h++){d.index>h?(g=d,d=null):g=d.sibling;var _=p(i,d,s[h],c);if(_===null){d===null&&(d=g);break}e&&d&&_.alternate===null&&t(i,d),o=a(_,o,h),u===null?l=_:u.sibling=_,u=_,d=g}if(h===s.length)return n(i,d),j&&Li(i,h),l;if(d===null){for(;h<s.length;h++)d=f(i,s[h],c),d!==null&&(o=a(d,o,h),u===null?l=d:u.sibling=d,u=d);return j&&Li(i,h),l}for(d=r(d);h<s.length;h++)g=m(d,i,h,s[h],c),g!==null&&(e&&g.alternate!==null&&d.delete(g.key===null?h:g.key),o=a(g,o,h),u===null?l=g:u.sibling=g,u=g);return e&&d.forEach(function(e){return t(i,e)}),j&&Li(i,h),l}function g(i,o,c,l){if(c==null)throw Error(s(151));for(var u=null,d=null,h=o,g=o=0,_=null,v=c.next();h!==null&&!v.done;g++,v=c.next()){h.index>g?(_=h,h=null):_=h.sibling;var y=p(i,h,v.value,l);if(y===null){h===null&&(h=_);break}e&&h&&y.alternate===null&&t(i,h),o=a(y,o,g),d===null?u=y:d.sibling=y,d=y,h=_}if(v.done)return n(i,h),j&&Li(i,g),u;if(h===null){for(;!v.done;g++,v=c.next())v=f(i,v.value,l),v!==null&&(o=a(v,o,g),d===null?u=v:d.sibling=v,d=v);return j&&Li(i,g),u}for(h=r(h);!v.done;g++,v=c.next())v=m(h,i,g,v.value,l),v!==null&&(e&&v.alternate!==null&&h.delete(v.key===null?g:v.key),o=a(v,o,g),d===null?u=v:d.sibling=v,d=v);return e&&h.forEach(function(e){return t(i,e)}),j&&Li(i,g),u}function b(e,r,a,c){if(typeof a==`object`&&a&&a.type===y&&a.key===null&&(a=a.props.children),typeof a==`object`&&a){switch(a.$$typeof){case _:a:{for(var l=a.key;r!==null;){if(r.key===l){if(l=a.type,l===y){if(r.tag===7){n(e,r.sibling),c=i(r,a.props.children),c.return=e,e=c;break a}}else if(r.elementType===l||typeof l==`object`&&l&&l.$$typeof===T&&Fa(l)===r.type){n(e,r.sibling),c=i(r,a.props),Ha(c,a),c.return=e,e=c;break a}n(e,r);break}t(e,r),r=r.sibling}a.type===y?(c=Si(a.props.children,e.mode,c,a.key),c.return=e,e=c):(c=xi(a.type,a.key,a.props,null,e.mode,c),Ha(c,a),c.return=e,e=c)}return o(e);case v:a:{for(l=a.key;r!==null;){if(r.key===l){if(r.tag===4&&r.stateNode.containerInfo===a.containerInfo&&r.stateNode.implementation===a.implementation){n(e,r.sibling),c=i(r,a.children||[]),c.return=e,e=c;break a}n(e,r);break}t(e,r),r=r.sibling}c=Ti(a,e.mode,c),c.return=e,e=c}return o(e);case T:return a=Fa(a),b(e,r,a,c)}if(le(a))return h(e,r,a,c);if(oe(a)){if(l=oe(a),typeof l!=`function`)throw Error(s(150));return a=l.call(a),g(e,r,a,c)}if(typeof a.then==`function`)return b(e,r,Va(a),c);if(a.$$typeof===C)return b(e,r,ua(e,a),c);Ua(e,a)}return typeof a==`string`&&a!==``||typeof a==`number`||typeof a==`bigint`?(a=``+a,r!==null&&r.tag===6?(n(e,r.sibling),c=i(r,a),c.return=e,e=c):(n(e,r),c=Ci(a,e.mode,c),c.return=e,e=c),o(e)):n(e,r)}return function(e,t,n,r){try{Ba=0;var i=b(e,t,n,r);return za=null,i}catch(t){if(t===ka||t===ja)throw t;var a=_i(29,t,null,e.mode);return a.lanes=r,a.return=e,a}}}var Ga=Wa(!0),Ka=Wa(!1),qa=!1;function Ja(e){e.updateQueue={baseState:e.memoizedState,firstBaseUpdate:null,lastBaseUpdate:null,shared:{pending:null,lanes:0,hiddenCallbacks:null},callbacks:null}}function Ya(e,t){e=e.updateQueue,t.updateQueue===e&&(t.updateQueue={baseState:e.baseState,firstBaseUpdate:e.firstBaseUpdate,lastBaseUpdate:e.lastBaseUpdate,shared:e.shared,callbacks:null})}function Xa(e){return{lane:e,tag:0,payload:null,callback:null,next:null}}function Za(e,t,n){var r=e.updateQueue;if(r===null)return null;if(r=r.shared,W&2){var i=r.pending;return i===null?t.next=t:(t.next=i.next,i.next=t),r.pending=t,t=mi(e),pi(e,null,n),t}return ui(e,r,t,n),mi(e)}function Qa(e,t,n){if(t=t.updateQueue,t!==null&&(t=t.shared,n&4194048)){var r=t.lanes;r&=e.pendingLanes,n|=r,t.lanes=n,lt(e,n)}}function $a(e,t){var n=e.updateQueue,r=e.alternate;if(r!==null&&(r=r.updateQueue,n===r)){var i=null,a=null;if(n=n.firstBaseUpdate,n!==null){do{var o={lane:n.lane,tag:n.tag,payload:n.payload,callback:null,next:null};a===null?i=a=o:a=a.next=o,n=n.next}while(n!==null);a===null?i=a=t:a=a.next=t}else i=a=t;n={baseState:r.baseState,firstBaseUpdate:i,lastBaseUpdate:a,shared:r.shared,callbacks:r.callbacks},e.updateQueue=n;return}e=n.lastBaseUpdate,e===null?n.firstBaseUpdate=t:e.next=t,n.lastBaseUpdate=t}var eo=!1;function to(){if(eo){var e=ba;if(e!==null)throw e}}function no(e,t,n,r){eo=!1;var i=e.updateQueue;qa=!1;var a=i.firstBaseUpdate,o=i.lastBaseUpdate,s=i.shared.pending;if(s!==null){i.shared.pending=null;var c=s,l=c.next;c.next=null,o===null?a=l:o.next=l,o=c;var u=e.alternate;u!==null&&(u=u.updateQueue,s=u.lastBaseUpdate,s!==o&&(s===null?u.firstBaseUpdate=l:s.next=l,u.lastBaseUpdate=c))}if(a!==null){var d=i.baseState;o=0,u=l=c=null,s=a;do{var f=s.lane&-536870913,p=f!==s.lane;if(p?(q&f)===f:(r&f)===f){f!==0&&f===ya&&(eo=!0),u!==null&&(u=u.next={lane:0,tag:s.tag,payload:s.payload,callback:null,next:null});a:{var m=e,g=s;f=t;var _=n;switch(g.tag){case 1:if(m=g.payload,typeof m==`function`){d=m.call(_,d,f);break a}d=m;break a;case 3:m.flags=m.flags&-65537|128;case 0:if(m=g.payload,f=typeof m==`function`?m.call(_,d,f):m,f==null)break a;d=h({},d,f);break a;case 2:qa=!0}}f=s.callback,f!==null&&(e.flags|=64,p&&(e.flags|=8192),p=i.callbacks,p===null?i.callbacks=[f]:p.push(f))}else p={lane:f,tag:s.tag,payload:s.payload,callback:s.callback,next:null},u===null?(l=u=p,c=d):u=u.next=p,o|=f;if(s=s.next,s===null){if(s=i.shared.pending,s===null)break;p=s,s=p.next,p.next=null,i.lastBaseUpdate=p,i.shared.pending=null}}while(1);u===null&&(c=d),i.baseState=c,i.firstBaseUpdate=l,i.lastBaseUpdate=u,a===null&&(i.shared.lanes=0),Kl|=o,e.lanes=o,e.memoizedState=d}}function ro(e,t){if(typeof e!=`function`)throw Error(s(191,e));e.call(t)}function io(e,t){var n=e.callbacks;if(n!==null)for(e.callbacks=null,e=0;e<n.length;e++)ro(n[e],t)}var ao=pe(null),oo=pe(0);function so(e,t){e=Gl,O(oo,e),O(ao,t),Gl=e|t.baseLanes}function co(){O(oo,Gl),O(ao,ao.current)}function lo(){Gl=oo.current,me(ao),me(oo)}var uo=pe(null),fo=null;function po(e){var t=e.alternate;O(N,N.current&1),O(uo,e),fo===null&&(t===null||ao.current!==null||t.memoizedState!==null)&&(fo=e)}function mo(e){O(N,N.current),O(uo,e),fo===null&&(fo=e)}function ho(e){e.tag===22?(O(N,N.current),O(uo,e),fo===null&&(fo=e)):go(e)}function go(){O(N,N.current),O(uo,uo.current)}function _o(e){me(uo),fo===e&&(fo=null),me(N)}var N=pe(0);function vo(e){for(var t=e;t!==null;){if(t.tag===13){var n=t.memoizedState;if(n!==null&&(n=n.dehydrated,n===null||af(n)||of(n)))return t}else if(t.tag===19&&(t.memoizedProps.revealOrder===`forwards`||t.memoizedProps.revealOrder===`backwards`||t.memoizedProps.revealOrder===`unstable_legacy-backwards`||t.memoizedProps.revealOrder===`together`)){if(t.flags&128)return t}else if(t.child!==null){t.child.return=t,t=t.child;continue}if(t===e)break;for(;t.sibling===null;){if(t.return===null||t.return===e)return null;t=t.return}t.sibling.return=t.return,t=t.sibling}return null}var yo=0,P=null,F=null,I=null,bo=!1,xo=!1,So=!1,Co=0,wo=0,To=null,Eo=0;function L(){throw Error(s(321))}function Do(e,t){if(t===null)return!1;for(var n=0;n<t.length&&n<e.length;n++)if(!jr(e[n],t[n]))return!1;return!0}function Oo(e,t,n,r,i,a){return yo=a,P=t,t.memoizedState=null,t.updateQueue=null,t.lanes=0,E.H=e===null||e.memoizedState===null?Ws:Gs,So=!1,a=n(r,i),So=!1,xo&&(a=Ao(t,n,r,i)),ko(e),a}function ko(e){E.H=Us;var t=F!==null&&F.next!==null;if(yo=0,I=F=P=null,bo=!1,wo=0,To=null,t)throw Error(s(300));e===null||z||(e=e.dependencies,e!==null&&sa(e)&&(z=!0))}function Ao(e,t,n,r){P=e;var i=0;do{if(xo&&(To=null),wo=0,xo=!1,25<=i)throw Error(s(301));if(i+=1,I=F=null,e.updateQueue!=null){var a=e.updateQueue;a.lastEffect=null,a.events=null,a.stores=null,a.memoCache!=null&&(a.memoCache.index=0)}E.H=Ks,a=t(n,r)}while(xo);return a}function jo(){var e=E.H,t=e.useState()[0];return t=typeof t.then==`function`?Lo(t):t,e=e.useState()[0],(F===null?null:F.memoizedState)!==e&&(P.flags|=1024),t}function Mo(){var e=Co!==0;return Co=0,e}function No(e,t,n){t.updateQueue=e.updateQueue,t.flags&=-2053,e.lanes&=~n}function Po(e){if(bo){for(e=e.memoizedState;e!==null;){var t=e.queue;t!==null&&(t.pending=null),e=e.next}bo=!1}yo=0,I=F=P=null,xo=!1,wo=Co=0,To=null}function Fo(){var e={memoizedState:null,baseState:null,baseQueue:null,queue:null,next:null};return I===null?P.memoizedState=I=e:I=I.next=e,I}function R(){if(F===null){var e=P.alternate;e=e===null?null:e.memoizedState}else e=F.next;var t=I===null?P.memoizedState:I.next;if(t!==null)I=t,F=e;else{if(e===null)throw P.alternate===null?Error(s(467)):Error(s(310));F=e,e={memoizedState:F.memoizedState,baseState:F.baseState,baseQueue:F.baseQueue,queue:F.queue,next:null},I===null?P.memoizedState=I=e:I=I.next=e}return I}function Io(){return{lastEffect:null,events:null,stores:null,memoCache:null}}function Lo(e){var t=wo;return wo+=1,To===null&&(To=[]),e=Pa(To,e,t),t=P,(I===null?t.memoizedState:I.next)===null&&(t=t.alternate,E.H=t===null||t.memoizedState===null?Ws:Gs),e}function Ro(e){if(typeof e==`object`&&e){if(typeof e.then==`function`)return Lo(e);if(e.$$typeof===C)return la(e)}throw Error(s(438,String(e)))}function zo(e){var t=null,n=P.updateQueue;if(n!==null&&(t=n.memoCache),t==null){var r=P.alternate;r!==null&&(r=r.updateQueue,r!==null&&(r=r.memoCache,r!=null&&(t={data:r.data.map(function(e){return e.slice()}),index:0})))}if(t??={data:[],index:0},n===null&&(n=Io(),P.updateQueue=n),n.memoCache=t,n=t.data[t.index],n===void 0)for(n=t.data[t.index]=Array(e),r=0;r<e;r++)n[r]=ie;return t.index++,n}function Bo(e,t){return typeof t==`function`?t(e):t}function Vo(e){return Ho(R(),F,e)}function Ho(e,t,n){var r=e.queue;if(r===null)throw Error(s(311));r.lastRenderedReducer=n;var i=e.baseQueue,a=r.pending;if(a!==null){if(i!==null){var o=i.next;i.next=a.next,a.next=o}t.baseQueue=i=a,r.pending=null}if(a=e.baseState,i===null)e.memoizedState=a;else{t=i.next;var c=o=null,l=null,u=t,d=!1;do{var f=u.lane&-536870913;if(f===u.lane?(yo&f)===f:(q&f)===f){var p=u.revertLane;if(p===0)l!==null&&(l=l.next={lane:0,revertLane:0,gesture:null,action:u.action,hasEagerState:u.hasEagerState,eagerState:u.eagerState,next:null}),f===ya&&(d=!0);else if((yo&p)===p){u=u.next,p===ya&&(d=!0);continue}else f={lane:0,revertLane:u.revertLane,gesture:null,action:u.action,hasEagerState:u.hasEagerState,eagerState:u.eagerState,next:null},l===null?(c=l=f,o=a):l=l.next=f,P.lanes|=p,Kl|=p;f=u.action,So&&n(a,f),a=u.hasEagerState?u.eagerState:n(a,f)}else p={lane:f,revertLane:u.revertLane,gesture:u.gesture,action:u.action,hasEagerState:u.hasEagerState,eagerState:u.eagerState,next:null},l===null?(c=l=p,o=a):l=l.next=p,P.lanes|=f,Kl|=f;u=u.next}while(u!==null&&u!==t);if(l===null?o=a:l.next=c,!jr(a,e.memoizedState)&&(z=!0,d&&(n=ba,n!==null)))throw n;e.memoizedState=a,e.baseState=o,e.baseQueue=l,r.lastRenderedState=a}return i===null&&(r.lanes=0),[e.memoizedState,r.dispatch]}function Uo(e){var t=R(),n=t.queue;if(n===null)throw Error(s(311));n.lastRenderedReducer=e;var r=n.dispatch,i=n.pending,a=t.memoizedState;if(i!==null){n.pending=null;var o=i=i.next;do a=e(a,o.action),o=o.next;while(o!==i);jr(a,t.memoizedState)||(z=!0),t.memoizedState=a,t.baseQueue===null&&(t.baseState=a),n.lastRenderedState=a}return[a,r]}function Wo(e,t,n){var r=P,i=R(),a=j;if(a){if(n===void 0)throw Error(s(407));n=n()}else n=t();var o=!jr((F||i).memoizedState,n);if(o&&(i.memoizedState=n,z=!0),i=i.queue,hs(qo.bind(null,r,i,e),[e]),i.getSnapshot!==t||o||I!==null&&I.memoizedState.tag&1){if(r.flags|=2048,us(9,{destroy:void 0},Ko.bind(null,r,i,n,t),null),G===null)throw Error(s(349));a||yo&127||Go(r,t,n)}return n}function Go(e,t,n){e.flags|=16384,e={getSnapshot:t,value:n},t=P.updateQueue,t===null?(t=Io(),P.updateQueue=t,t.stores=[e]):(n=t.stores,n===null?t.stores=[e]:n.push(e))}function Ko(e,t,n,r){t.value=n,t.getSnapshot=r,Jo(t)&&Yo(e)}function qo(e,t,n){return n(function(){Jo(t)&&Yo(e)})}function Jo(e){var t=e.getSnapshot;e=e.value;try{var n=t();return!jr(e,n)}catch{return!0}}function Yo(e){var t=fi(e,2);t!==null&&hu(t,e,2)}function Xo(e){var t=Fo();if(typeof e==`function`){var n=e;if(e=n(),So){Ke(!0);try{n()}finally{Ke(!1)}}}return t.memoizedState=t.baseState=e,t.queue={pending:null,lanes:0,dispatch:null,lastRenderedReducer:Bo,lastRenderedState:e},t}function Zo(e,t,n,r){return e.baseState=n,Ho(e,F,typeof r==`function`?r:Bo)}function Qo(e,t,n,r,i){if(Bs(e))throw Error(s(485));if(e=t.action,e!==null){var a={payload:i,action:e,next:null,isTransition:!0,status:`pending`,value:null,reason:null,listeners:[],then:function(e){a.listeners.push(e)}};E.T===null?a.isTransition=!1:n(!0),r(a),n=t.pending,n===null?(a.next=t.pending=a,$o(t,a)):(a.next=n.next,t.pending=n.next=a)}}function $o(e,t){var n=t.action,r=t.payload,i=e.state;if(t.isTransition){var a=E.T,o={};E.T=o;try{var s=n(i,r),c=E.S;c!==null&&c(o,s),es(e,t,s)}catch(n){ns(e,t,n)}finally{a!==null&&o.types!==null&&(a.types=o.types),E.T=a}}else try{a=n(i,r),es(e,t,a)}catch(n){ns(e,t,n)}}function es(e,t,n){typeof n==`object`&&n&&typeof n.then==`function`?n.then(function(n){ts(e,t,n)},function(n){return ns(e,t,n)}):ts(e,t,n)}function ts(e,t,n){t.status=`fulfilled`,t.value=n,rs(t),e.state=n,t=e.pending,t!==null&&(n=t.next,n===t?e.pending=null:(n=n.next,t.next=n,$o(e,n)))}function ns(e,t,n){var r=e.pending;if(e.pending=null,r!==null){r=r.next;do t.status=`rejected`,t.reason=n,rs(t),t=t.next;while(t!==r)}e.action=null}function rs(e){e=e.listeners;for(var t=0;t<e.length;t++)(0,e[t])()}function is(e,t){return t}function as(e,t){if(j){var n=G.formState;if(n!==null){a:{var r=P;if(j){if(A){b:{for(var i=A,a=Wi;i.nodeType!==8;){if(!a){i=null;break b}if(i=cf(i.nextSibling),i===null){i=null;break b}}a=i.data,i=a===`F!`||a===`F`?i:null}if(i){A=cf(i.nextSibling),r=i.data===`F!`;break a}}Ki(r)}r=!1}r&&(t=n[0])}}return n=Fo(),n.memoizedState=n.baseState=t,r={pending:null,lanes:0,dispatch:null,lastRenderedReducer:is,lastRenderedState:t},n.queue=r,n=Ls.bind(null,P,r),r.dispatch=n,r=Xo(!1),a=zs.bind(null,P,!1,r.queue),r=Fo(),i={state:t,dispatch:null,action:e,pending:null},r.queue=i,n=Qo.bind(null,P,i,a,n),i.dispatch=n,r.memoizedState=e,[t,n,!1]}function os(e){return ss(R(),F,e)}function ss(e,t,n){if(t=Ho(e,t,is)[0],e=Vo(Bo)[0],typeof t==`object`&&t&&typeof t.then==`function`)try{var r=Lo(t)}catch(e){throw e===ka?ja:e}else r=t;t=R();var i=t.queue,a=i.dispatch;return n!==t.memoizedState&&(P.flags|=2048,us(9,{destroy:void 0},cs.bind(null,i,n),null)),[r,a,e]}function cs(e,t){e.action=t}function ls(e){var t=R(),n=F;if(n!==null)return ss(t,n,e);R(),t=t.memoizedState,n=R();var r=n.queue.dispatch;return n.memoizedState=e,[t,r,!1]}function us(e,t,n,r){return e={tag:e,create:n,deps:r,inst:t,next:null},t=P.updateQueue,t===null&&(t=Io(),P.updateQueue=t),n=t.lastEffect,n===null?t.lastEffect=e.next=e:(r=n.next,n.next=e,e.next=r,t.lastEffect=e),e}function ds(){return R().memoizedState}function fs(e,t,n,r){var i=Fo();P.flags|=e,i.memoizedState=us(1|t,{destroy:void 0},n,r===void 0?null:r)}function ps(e,t,n,r){var i=R();r=r===void 0?null:r;var a=i.memoizedState.inst;F!==null&&r!==null&&Do(r,F.memoizedState.deps)?i.memoizedState=us(t,a,n,r):(P.flags|=e,i.memoizedState=us(1|t,a,n,r))}function ms(e,t){fs(8390656,8,e,t)}function hs(e,t){ps(2048,8,e,t)}function gs(e){P.flags|=4;var t=P.updateQueue;if(t===null)t=Io(),P.updateQueue=t,t.events=[e];else{var n=t.events;n===null?t.events=[e]:n.push(e)}}function _s(e){var t=R().memoizedState;return gs({ref:t,nextImpl:e}),function(){if(W&2)throw Error(s(440));return t.impl.apply(void 0,arguments)}}function vs(e,t){return ps(4,2,e,t)}function ys(e,t){return ps(4,4,e,t)}function bs(e,t){if(typeof t==`function`){e=e();var n=t(e);return function(){typeof n==`function`?n():t(null)}}if(t!=null)return e=e(),t.current=e,function(){t.current=null}}function xs(e,t,n){n=n==null?null:n.concat([e]),ps(4,4,bs.bind(null,t,e),n)}function Ss(){}function Cs(e,t){var n=R();t=t===void 0?null:t;var r=n.memoizedState;return t!==null&&Do(t,r[1])?r[0]:(n.memoizedState=[e,t],e)}function ws(e,t){var n=R();t=t===void 0?null:t;var r=n.memoizedState;if(t!==null&&Do(t,r[1]))return r[0];if(r=e(),So){Ke(!0);try{e()}finally{Ke(!1)}}return n.memoizedState=[r,t],r}function Ts(e,t,n){return n===void 0||yo&1073741824&&!(q&261930)?e.memoizedState=t:(e.memoizedState=n,e=mu(),P.lanes|=e,Kl|=e,n)}function Es(e,t,n,r){return jr(n,t)?n:ao.current===null?!(yo&42)||yo&1073741824&&!(q&261930)?(z=!0,e.memoizedState=n):(e=mu(),P.lanes|=e,Kl|=e,t):(e=Ts(e,n,r),jr(e,t)||(z=!0),e)}function Ds(e,t,n,r,i){var a=D.p;D.p=a!==0&&8>a?a:8;var o=E.T,s={};E.T=s,zs(e,!1,t,n);try{var c=i(),l=E.S;l!==null&&l(s,c),typeof c==`object`&&c&&typeof c.then==`function`?Rs(e,t,Ca(c,r),pu(e)):Rs(e,t,r,pu(e))}catch(n){Rs(e,t,{then:function(){},status:`rejected`,reason:n},pu())}finally{D.p=a,o!==null&&s.types!==null&&(o.types=s.types),E.T=o}}function Os(){}function ks(e,t,n,r){if(e.tag!==5)throw Error(s(476));var i=As(e).queue;Ds(e,i,t,ue,n===null?Os:function(){return js(e),n(r)})}function As(e){var t=e.memoizedState;if(t!==null)return t;t={memoizedState:ue,baseState:ue,baseQueue:null,queue:{pending:null,lanes:0,dispatch:null,lastRenderedReducer:Bo,lastRenderedState:ue},next:null};var n={};return t.next={memoizedState:n,baseState:n,baseQueue:null,queue:{pending:null,lanes:0,dispatch:null,lastRenderedReducer:Bo,lastRenderedState:n},next:null},e.memoizedState=t,e=e.alternate,e!==null&&(e.memoizedState=t),t}function js(e){var t=As(e);t.next===null&&(t=e.alternate.memoizedState),Rs(e,t.next.queue,{},pu())}function Ms(){return la(Qf)}function Ns(){return R().memoizedState}function Ps(){return R().memoizedState}function Fs(e){for(var t=e.return;t!==null;){switch(t.tag){case 24:case 3:var n=pu();e=Xa(n);var r=Za(t,e,n);r!==null&&(hu(r,t,n),Qa(r,t,n)),t={cache:ha()},e.payload=t;return}t=t.return}}function Is(e,t,n){var r=pu();n={lane:r,revertLane:0,gesture:null,action:n,hasEagerState:!1,eagerState:null,next:null},Bs(e)?Vs(t,n):(n=di(e,t,n,r),n!==null&&(hu(n,e,r),Hs(n,t,r)))}function Ls(e,t,n){Rs(e,t,n,pu())}function Rs(e,t,n,r){var i={lane:r,revertLane:0,gesture:null,action:n,hasEagerState:!1,eagerState:null,next:null};if(Bs(e))Vs(t,i);else{var a=e.alternate;if(e.lanes===0&&(a===null||a.lanes===0)&&(a=t.lastRenderedReducer,a!==null))try{var o=t.lastRenderedState,s=a(o,n);if(i.hasEagerState=!0,i.eagerState=s,jr(s,o))return ui(e,t,i,0),G===null&&li(),!1}catch{}if(n=di(e,t,i,r),n!==null)return hu(n,e,r),Hs(n,t,r),!0}return!1}function zs(e,t,n,r){if(r={lane:2,revertLane:dd(),gesture:null,action:r,hasEagerState:!1,eagerState:null,next:null},Bs(e)){if(t)throw Error(s(479))}else t=di(e,n,r,2),t!==null&&hu(t,e,2)}function Bs(e){var t=e.alternate;return e===P||t!==null&&t===P}function Vs(e,t){xo=bo=!0;var n=e.pending;n===null?t.next=t:(t.next=n.next,n.next=t),e.pending=t}function Hs(e,t,n){if(n&4194048){var r=t.lanes;r&=e.pendingLanes,n|=r,t.lanes=n,lt(e,n)}}var Us={readContext:la,use:Ro,useCallback:L,useContext:L,useEffect:L,useImperativeHandle:L,useLayoutEffect:L,useInsertionEffect:L,useMemo:L,useReducer:L,useRef:L,useState:L,useDebugValue:L,useDeferredValue:L,useTransition:L,useSyncExternalStore:L,useId:L,useHostTransitionStatus:L,useFormState:L,useActionState:L,useOptimistic:L,useMemoCache:L,useCacheRefresh:L};Us.useEffectEvent=L;var Ws={readContext:la,use:Ro,useCallback:function(e,t){return Fo().memoizedState=[e,t===void 0?null:t],e},useContext:la,useEffect:ms,useImperativeHandle:function(e,t,n){n=n==null?null:n.concat([e]),fs(4194308,4,bs.bind(null,t,e),n)},useLayoutEffect:function(e,t){return fs(4194308,4,e,t)},useInsertionEffect:function(e,t){fs(4,2,e,t)},useMemo:function(e,t){var n=Fo();t=t===void 0?null:t;var r=e();if(So){Ke(!0);try{e()}finally{Ke(!1)}}return n.memoizedState=[r,t],r},useReducer:function(e,t,n){var r=Fo();if(n!==void 0){var i=n(t);if(So){Ke(!0);try{n(t)}finally{Ke(!1)}}}else i=t;return r.memoizedState=r.baseState=i,e={pending:null,lanes:0,dispatch:null,lastRenderedReducer:e,lastRenderedState:i},r.queue=e,e=e.dispatch=Is.bind(null,P,e),[r.memoizedState,e]},useRef:function(e){var t=Fo();return e={current:e},t.memoizedState=e},useState:function(e){e=Xo(e);var t=e.queue,n=Ls.bind(null,P,t);return t.dispatch=n,[e.memoizedState,n]},useDebugValue:Ss,useDeferredValue:function(e,t){return Ts(Fo(),e,t)},useTransition:function(){var e=Xo(!1);return e=Ds.bind(null,P,e.queue,!0,!1),Fo().memoizedState=e,[!1,e]},useSyncExternalStore:function(e,t,n){var r=P,i=Fo();if(j){if(n===void 0)throw Error(s(407));n=n()}else{if(n=t(),G===null)throw Error(s(349));q&127||Go(r,t,n)}i.memoizedState=n;var a={value:n,getSnapshot:t};return i.queue=a,ms(qo.bind(null,r,a,e),[e]),r.flags|=2048,us(9,{destroy:void 0},Ko.bind(null,r,a,n,t),null),n},useId:function(){var e=Fo(),t=G.identifierPrefix;if(j){var n=Ii,r=Fi;n=(r&~(1<<32-qe(r)-1)).toString(32)+n,t=`_`+t+`R_`+n,n=Co++,0<n&&(t+=`H`+n.toString(32)),t+=`_`}else n=Eo++,t=`_`+t+`r_`+n.toString(32)+`_`;return e.memoizedState=t},useHostTransitionStatus:Ms,useFormState:as,useActionState:as,useOptimistic:function(e){var t=Fo();t.memoizedState=t.baseState=e;var n={pending:null,lanes:0,dispatch:null,lastRenderedReducer:null,lastRenderedState:null};return t.queue=n,t=zs.bind(null,P,!0,n),n.dispatch=t,[e,t]},useMemoCache:zo,useCacheRefresh:function(){return Fo().memoizedState=Fs.bind(null,P)},useEffectEvent:function(e){var t=Fo(),n={impl:e};return t.memoizedState=n,function(){if(W&2)throw Error(s(440));return n.impl.apply(void 0,arguments)}}},Gs={readContext:la,use:Ro,useCallback:Cs,useContext:la,useEffect:hs,useImperativeHandle:xs,useInsertionEffect:vs,useLayoutEffect:ys,useMemo:ws,useReducer:Vo,useRef:ds,useState:function(){return Vo(Bo)},useDebugValue:Ss,useDeferredValue:function(e,t){return Es(R(),F.memoizedState,e,t)},useTransition:function(){var e=Vo(Bo)[0],t=R().memoizedState;return[typeof e==`boolean`?e:Lo(e),t]},useSyncExternalStore:Wo,useId:Ns,useHostTransitionStatus:Ms,useFormState:os,useActionState:os,useOptimistic:function(e,t){return Zo(R(),F,e,t)},useMemoCache:zo,useCacheRefresh:Ps};Gs.useEffectEvent=_s;var Ks={readContext:la,use:Ro,useCallback:Cs,useContext:la,useEffect:hs,useImperativeHandle:xs,useInsertionEffect:vs,useLayoutEffect:ys,useMemo:ws,useReducer:Uo,useRef:ds,useState:function(){return Uo(Bo)},useDebugValue:Ss,useDeferredValue:function(e,t){var n=R();return F===null?Ts(n,e,t):Es(n,F.memoizedState,e,t)},useTransition:function(){var e=Uo(Bo)[0],t=R().memoizedState;return[typeof e==`boolean`?e:Lo(e),t]},useSyncExternalStore:Wo,useId:Ns,useHostTransitionStatus:Ms,useFormState:ls,useActionState:ls,useOptimistic:function(e,t){var n=R();return F===null?(n.baseState=e,[e,n.queue.dispatch]):Zo(n,F,e,t)},useMemoCache:zo,useCacheRefresh:Ps};Ks.useEffectEvent=_s;function qs(e,t,n,r){t=e.memoizedState,n=n(r,t),n=n==null?t:h({},t,n),e.memoizedState=n,e.lanes===0&&(e.updateQueue.baseState=n)}var Js={enqueueSetState:function(e,t,n){e=e._reactInternals;var r=pu(),i=Xa(r);i.payload=t,n!=null&&(i.callback=n),t=Za(e,i,r),t!==null&&(hu(t,e,r),Qa(t,e,r))},enqueueReplaceState:function(e,t,n){e=e._reactInternals;var r=pu(),i=Xa(r);i.tag=1,i.payload=t,n!=null&&(i.callback=n),t=Za(e,i,r),t!==null&&(hu(t,e,r),Qa(t,e,r))},enqueueForceUpdate:function(e,t){e=e._reactInternals;var n=pu(),r=Xa(n);r.tag=2,t!=null&&(r.callback=t),t=Za(e,r,n),t!==null&&(hu(t,e,n),Qa(t,e,n))}};function Ys(e,t,n,r,i,a,o){return e=e.stateNode,typeof e.shouldComponentUpdate==`function`?e.shouldComponentUpdate(r,a,o):t.prototype&&t.prototype.isPureReactComponent?!Mr(n,r)||!Mr(i,a):!0}function Xs(e,t,n,r){e=t.state,typeof t.componentWillReceiveProps==`function`&&t.componentWillReceiveProps(n,r),typeof t.UNSAFE_componentWillReceiveProps==`function`&&t.UNSAFE_componentWillReceiveProps(n,r),t.state!==e&&Js.enqueueReplaceState(t,t.state,null)}function Zs(e,t){var n=t;if(`ref`in t)for(var r in n={},t)r!==`ref`&&(n[r]=t[r]);if(e=e.defaultProps)for(var i in n===t&&(n=h({},n)),e)n[i]===void 0&&(n[i]=e[i]);return n}function Qs(e){ai(e)}function $s(e){console.error(e)}function ec(e){ai(e)}function tc(e,t){try{var n=e.onUncaughtError;n(t.value,{componentStack:t.stack})}catch(e){setTimeout(function(){throw e})}}function nc(e,t,n){try{var r=e.onCaughtError;r(n.value,{componentStack:n.stack,errorBoundary:t.tag===1?t.stateNode:null})}catch(e){setTimeout(function(){throw e})}}function rc(e,t,n){return n=Xa(n),n.tag=3,n.payload={element:null},n.callback=function(){tc(e,t)},n}function ic(e){return e=Xa(e),e.tag=3,e}function ac(e,t,n,r){var i=n.type.getDerivedStateFromError;if(typeof i==`function`){var a=r.value;e.payload=function(){return i(a)},e.callback=function(){nc(t,n,r)}}var o=n.stateNode;o!==null&&typeof o.componentDidCatch==`function`&&(e.callback=function(){nc(t,n,r),typeof i!=`function`&&(iu===null?iu=new Set([this]):iu.add(this));var e=r.stack;this.componentDidCatch(r.value,{componentStack:e===null?``:e})})}function oc(e,t,n,r,i){if(n.flags|=32768,typeof r==`object`&&r&&typeof r.then==`function`){if(t=n.alternate,t!==null&&oa(t,n,i,!0),n=uo.current,n!==null){switch(n.tag){case 31:case 13:return fo===null?Du():n.alternate===null&&Y===0&&(Y=3),n.flags&=-257,n.flags|=65536,n.lanes=i,r===Ma?n.flags|=16384:(t=n.updateQueue,t===null?n.updateQueue=new Set([r]):t.add(r),Gu(e,r,i)),!1;case 22:return n.flags|=65536,r===Ma?n.flags|=16384:(t=n.updateQueue,t===null?(t={transitions:null,markerInstances:null,retryQueue:new Set([r])},n.updateQueue=t):(n=t.retryQueue,n===null?t.retryQueue=new Set([r]):n.add(r)),Gu(e,r,i)),!1}throw Error(s(435,n.tag))}return Gu(e,r,i),Du(),!1}if(j)return t=uo.current,t===null?(r!==Gi&&(t=Error(s(423),{cause:r}),Qi(Di(t,n))),e=e.current.alternate,e.flags|=65536,i&=-i,e.lanes|=i,r=Di(r,n),i=rc(e.stateNode,r,i),$a(e,i),Y!==4&&(Y=2)):(!(t.flags&65536)&&(t.flags|=256),t.flags|=65536,t.lanes=i,r!==Gi&&(e=Error(s(422),{cause:r}),Qi(Di(e,n)))),!1;var a=Error(s(520),{cause:r});if(a=Di(a,n),Zl===null?Zl=[a]:Zl.push(a),Y!==4&&(Y=2),t===null)return!0;r=Di(r,n),n=t;do{switch(n.tag){case 3:return n.flags|=65536,e=i&-i,n.lanes|=e,e=rc(n.stateNode,r,e),$a(n,e),!1;case 1:if(t=n.type,a=n.stateNode,!(n.flags&128)&&(typeof t.getDerivedStateFromError==`function`||a!==null&&typeof a.componentDidCatch==`function`&&(iu===null||!iu.has(a))))return n.flags|=65536,i&=-i,n.lanes|=i,i=ic(i),ac(i,e,n,r),$a(n,i),!1}n=n.return}while(n!==null);return!1}var sc=Error(s(461)),z=!1;function cc(e,t,n,r){t.child=e===null?Ka(t,null,n,r):Ga(t,e.child,n,r)}function lc(e,t,n,r,i){n=n.render;var a=t.ref;if(`ref`in r){var o={};for(var s in r)s!==`ref`&&(o[s]=r[s])}else o=r;return ca(t),r=Oo(e,t,n,o,a,i),s=Mo(),e!==null&&!z?(No(e,t,i),Nc(e,t,i)):(j&&s&&zi(t),t.flags|=1,cc(e,t,r,i),t.child)}function uc(e,t,n,r,i){if(e===null){var a=n.type;return typeof a==`function`&&!vi(a)&&a.defaultProps===void 0&&n.compare===null?(t.tag=15,t.type=a,dc(e,t,a,r,i)):(e=xi(n.type,null,r,t,t.mode,i),e.ref=t.ref,e.return=t,t.child=e)}if(a=e.child,!Pc(e,i)){var o=a.memoizedProps;if(n=n.compare,n=n===null?Mr:n,n(o,r)&&e.ref===t.ref)return Nc(e,t,i)}return t.flags|=1,e=yi(a,r),e.ref=t.ref,e.return=t,t.child=e}function dc(e,t,n,r,i){if(e!==null){var a=e.memoizedProps;if(Mr(a,r)&&e.ref===t.ref){if(z=!1,t.pendingProps=r=a,Pc(e,i))e.flags&131072&&(z=!0);else return t.lanes=e.lanes,Nc(e,t,i)}}return yc(e,t,n,r,i)}function fc(e,t,n,r){var i=r.children,a=e===null?null:e.memoizedState;if(e===null&&t.stateNode===null&&(t.stateNode={_visibility:1,_pendingMarkers:null,_retryCache:null,_transitions:null}),r.mode===`hidden`){if(t.flags&128){if(a=a===null?n:a.baseLanes|n,e!==null){for(r=t.child=e.child,i=0;r!==null;)i=i|r.lanes|r.childLanes,r=r.sibling;r=i&~a}else r=0,t.child=null;return mc(e,t,a,n,r)}if(n&536870912)t.memoizedState={baseLanes:0,cachePool:null},e!==null&&Da(t,a===null?null:a.cachePool),a===null?co():so(t,a),ho(t);else return r=t.lanes=536870912,mc(e,t,a===null?n:a.baseLanes|n,n,r)}else a===null?(e!==null&&Da(t,null),co(),go(t)):(Da(t,a.cachePool),so(t,a),go(t),t.memoizedState=null);return cc(e,t,i,n),t.child}function pc(e,t){return e!==null&&e.tag===22||t.stateNode!==null||(t.stateNode={_visibility:1,_pendingMarkers:null,_retryCache:null,_transitions:null}),t.sibling}function mc(e,t,n,r,i){var a=Ea();return a=a===null?null:{parent:M._currentValue,pool:a},t.memoizedState={baseLanes:n,cachePool:a},e!==null&&Da(t,null),co(),ho(t),e!==null&&oa(e,t,r,!0),t.childLanes=i,null}function hc(e,t){return t=Oc({mode:t.mode,children:t.children},e.mode),t.ref=e.ref,e.child=t,t.return=e,t}function gc(e,t,n){return Ga(t,e.child,null,n),e=hc(t,t.pendingProps),e.flags|=2,_o(t),t.memoizedState=null,e}function _c(e,t,n){var r=t.pendingProps,i=!!(t.flags&128);if(t.flags&=-129,e===null){if(j){if(r.mode===`hidden`)return e=hc(t,r),t.lanes=536870912,pc(null,e);if(mo(t),(e=A)?(e=rf(e,Wi),e=e!==null&&e.data===`&`?e:null,e!==null&&(t.memoizedState={dehydrated:e,treeContext:Pi===null?null:{id:Fi,overflow:Ii},retryLane:536870912,hydrationErrors:null},n=wi(e),n.return=t,t.child=n,Hi=t,A=null)):e=null,e===null)throw Ki(t);return t.lanes=536870912,null}return hc(t,r)}var a=e.memoizedState;if(a!==null){var o=a.dehydrated;if(mo(t),i){if(t.flags&256)t.flags&=-257,t=gc(e,t,n);else if(t.memoizedState!==null)t.child=e.child,t.flags|=128,t=null;else throw Error(s(558))}else if(z||oa(e,t,n,!1),i=(n&e.childLanes)!==0,z||i){if(r=G,r!==null&&(o=ut(r,n),o!==0&&o!==a.retryLane))throw a.retryLane=o,fi(e,o),hu(r,e,o),sc;Du(),t=gc(e,t,n)}else e=a.treeContext,A=cf(o.nextSibling),Hi=t,j=!0,Ui=null,Wi=!1,e!==null&&Vi(t,e),t=hc(t,r),t.flags|=4096;return t}return e=yi(e.child,{mode:r.mode,children:r.children}),e.ref=t.ref,t.child=e,e.return=t,e}function vc(e,t){var n=t.ref;if(n===null)e!==null&&e.ref!==null&&(t.flags|=4194816);else{if(typeof n!=`function`&&typeof n!=`object`)throw Error(s(284));(e===null||e.ref!==n)&&(t.flags|=4194816)}}function yc(e,t,n,r,i){return ca(t),n=Oo(e,t,n,r,void 0,i),r=Mo(),e!==null&&!z?(No(e,t,i),Nc(e,t,i)):(j&&r&&zi(t),t.flags|=1,cc(e,t,n,i),t.child)}function bc(e,t,n,r,i,a){return ca(t),t.updateQueue=null,n=Ao(t,r,n,i),ko(e),r=Mo(),e!==null&&!z?(No(e,t,a),Nc(e,t,a)):(j&&r&&zi(t),t.flags|=1,cc(e,t,n,a),t.child)}function xc(e,t,n,r,i){if(ca(t),t.stateNode===null){var a=hi,o=n.contextType;typeof o==`object`&&o&&(a=la(o)),a=new n(r,a),t.memoizedState=a.state!==null&&a.state!==void 0?a.state:null,a.updater=Js,t.stateNode=a,a._reactInternals=t,a=t.stateNode,a.props=r,a.state=t.memoizedState,a.refs={},Ja(t),o=n.contextType,a.context=typeof o==`object`&&o?la(o):hi,a.state=t.memoizedState,o=n.getDerivedStateFromProps,typeof o==`function`&&(qs(t,n,o,r),a.state=t.memoizedState),typeof n.getDerivedStateFromProps==`function`||typeof a.getSnapshotBeforeUpdate==`function`||typeof a.UNSAFE_componentWillMount!=`function`&&typeof a.componentWillMount!=`function`||(o=a.state,typeof a.componentWillMount==`function`&&a.componentWillMount(),typeof a.UNSAFE_componentWillMount==`function`&&a.UNSAFE_componentWillMount(),o!==a.state&&Js.enqueueReplaceState(a,a.state,null),no(t,r,a,i),to(),a.state=t.memoizedState),typeof a.componentDidMount==`function`&&(t.flags|=4194308),r=!0}else if(e===null){a=t.stateNode;var s=t.memoizedProps,c=Zs(n,s);a.props=c;var l=a.context,u=n.contextType;o=hi,typeof u==`object`&&u&&(o=la(u));var d=n.getDerivedStateFromProps;u=typeof d==`function`||typeof a.getSnapshotBeforeUpdate==`function`,s=t.pendingProps!==s,u||typeof a.UNSAFE_componentWillReceiveProps!=`function`&&typeof a.componentWillReceiveProps!=`function`||(s||l!==o)&&Xs(t,a,r,o),qa=!1;var f=t.memoizedState;a.state=f,no(t,r,a,i),to(),l=t.memoizedState,s||f!==l||qa?(typeof d==`function`&&(qs(t,n,d,r),l=t.memoizedState),(c=qa||Ys(t,n,c,r,f,l,o))?(u||typeof a.UNSAFE_componentWillMount!=`function`&&typeof a.componentWillMount!=`function`||(typeof a.componentWillMount==`function`&&a.componentWillMount(),typeof a.UNSAFE_componentWillMount==`function`&&a.UNSAFE_componentWillMount()),typeof a.componentDidMount==`function`&&(t.flags|=4194308)):(typeof a.componentDidMount==`function`&&(t.flags|=4194308),t.memoizedProps=r,t.memoizedState=l),a.props=r,a.state=l,a.context=o,r=c):(typeof a.componentDidMount==`function`&&(t.flags|=4194308),r=!1)}else{a=t.stateNode,Ya(e,t),o=t.memoizedProps,u=Zs(n,o),a.props=u,d=t.pendingProps,f=a.context,l=n.contextType,c=hi,typeof l==`object`&&l&&(c=la(l)),s=n.getDerivedStateFromProps,(l=typeof s==`function`||typeof a.getSnapshotBeforeUpdate==`function`)||typeof a.UNSAFE_componentWillReceiveProps!=`function`&&typeof a.componentWillReceiveProps!=`function`||(o!==d||f!==c)&&Xs(t,a,r,c),qa=!1,f=t.memoizedState,a.state=f,no(t,r,a,i),to();var p=t.memoizedState;o!==d||f!==p||qa||e!==null&&e.dependencies!==null&&sa(e.dependencies)?(typeof s==`function`&&(qs(t,n,s,r),p=t.memoizedState),(u=qa||Ys(t,n,u,r,f,p,c)||e!==null&&e.dependencies!==null&&sa(e.dependencies))?(l||typeof a.UNSAFE_componentWillUpdate!=`function`&&typeof a.componentWillUpdate!=`function`||(typeof a.componentWillUpdate==`function`&&a.componentWillUpdate(r,p,c),typeof a.UNSAFE_componentWillUpdate==`function`&&a.UNSAFE_componentWillUpdate(r,p,c)),typeof a.componentDidUpdate==`function`&&(t.flags|=4),typeof a.getSnapshotBeforeUpdate==`function`&&(t.flags|=1024)):(typeof a.componentDidUpdate!=`function`||o===e.memoizedProps&&f===e.memoizedState||(t.flags|=4),typeof a.getSnapshotBeforeUpdate!=`function`||o===e.memoizedProps&&f===e.memoizedState||(t.flags|=1024),t.memoizedProps=r,t.memoizedState=p),a.props=r,a.state=p,a.context=c,r=u):(typeof a.componentDidUpdate!=`function`||o===e.memoizedProps&&f===e.memoizedState||(t.flags|=4),typeof a.getSnapshotBeforeUpdate!=`function`||o===e.memoizedProps&&f===e.memoizedState||(t.flags|=1024),r=!1)}return a=r,vc(e,t),r=!!(t.flags&128),a||r?(a=t.stateNode,n=r&&typeof n.getDerivedStateFromError!=`function`?null:a.render(),t.flags|=1,e!==null&&r?(t.child=Ga(t,e.child,null,i),t.child=Ga(t,null,n,i)):cc(e,t,n,i),t.memoizedState=a.state,e=t.child):e=Nc(e,t,i),e}function Sc(e,t,n,r){return Xi(),t.flags|=256,cc(e,t,n,r),t.child}var Cc={dehydrated:null,treeContext:null,retryLane:0,hydrationErrors:null};function wc(e){return{baseLanes:e,cachePool:Oa()}}function Tc(e,t,n){return e=e===null?0:e.childLanes&~n,t&&(e|=Yl),e}function Ec(e,t,n){var r=t.pendingProps,i=!1,a=!!(t.flags&128),o;if((o=a)||(o=e!==null&&e.memoizedState===null?!1:!!(N.current&2)),o&&(i=!0,t.flags&=-129),o=!!(t.flags&32),t.flags&=-33,e===null){if(j){if(i?po(t):go(t),(e=A)?(e=rf(e,Wi),e=e!==null&&e.data!==`&`?e:null,e!==null&&(t.memoizedState={dehydrated:e,treeContext:Pi===null?null:{id:Fi,overflow:Ii},retryLane:536870912,hydrationErrors:null},n=wi(e),n.return=t,t.child=n,Hi=t,A=null)):e=null,e===null)throw Ki(t);return of(e)?t.lanes=32:t.lanes=536870912,null}var c=r.children;return r=r.fallback,i?(go(t),i=t.mode,c=Oc({mode:`hidden`,children:c},i),r=Si(r,i,n,null),c.return=t,r.return=t,c.sibling=r,t.child=c,r=t.child,r.memoizedState=wc(n),r.childLanes=Tc(e,o,n),t.memoizedState=Cc,pc(null,r)):(po(t),Dc(t,c))}var l=e.memoizedState;if(l!==null&&(c=l.dehydrated,c!==null)){if(a)t.flags&256?(po(t),t.flags&=-257,t=kc(e,t,n)):t.memoizedState===null?(go(t),c=r.fallback,i=t.mode,r=Oc({mode:`visible`,children:r.children},i),c=Si(c,i,n,null),c.flags|=2,r.return=t,c.return=t,r.sibling=c,t.child=r,Ga(t,e.child,null,n),r=t.child,r.memoizedState=wc(n),r.childLanes=Tc(e,o,n),t.memoizedState=Cc,t=pc(null,r)):(go(t),t.child=e.child,t.flags|=128,t=null);else if(po(t),of(c)){if(o=c.nextSibling&&c.nextSibling.dataset,o)var u=o.dgst;o=u,r=Error(s(419)),r.stack=``,r.digest=o,Qi({value:r,source:null,stack:null}),t=kc(e,t,n)}else if(z||oa(e,t,n,!1),o=(n&e.childLanes)!==0,z||o){if(o=G,o!==null&&(r=ut(o,n),r!==0&&r!==l.retryLane))throw l.retryLane=r,fi(e,r),hu(o,e,r),sc;af(c)||Du(),t=kc(e,t,n)}else af(c)?(t.flags|=192,t.child=e.child,t=null):(e=l.treeContext,A=cf(c.nextSibling),Hi=t,j=!0,Ui=null,Wi=!1,e!==null&&Vi(t,e),t=Dc(t,r.children),t.flags|=4096);return t}return i?(go(t),c=r.fallback,i=t.mode,l=e.child,u=l.sibling,r=yi(l,{mode:`hidden`,children:r.children}),r.subtreeFlags=l.subtreeFlags&65011712,u===null?(c=Si(c,i,n,null),c.flags|=2):c=yi(u,c),c.return=t,r.return=t,r.sibling=c,t.child=r,pc(null,r),r=t.child,c=e.child.memoizedState,c===null?c=wc(n):(i=c.cachePool,i===null?i=Oa():(l=M._currentValue,i=i.parent===l?i:{parent:l,pool:l}),c={baseLanes:c.baseLanes|n,cachePool:i}),r.memoizedState=c,r.childLanes=Tc(e,o,n),t.memoizedState=Cc,pc(e.child,r)):(po(t),n=e.child,e=n.sibling,n=yi(n,{mode:`visible`,children:r.children}),n.return=t,n.sibling=null,e!==null&&(o=t.deletions,o===null?(t.deletions=[e],t.flags|=16):o.push(e)),t.child=n,t.memoizedState=null,n)}function Dc(e,t){return t=Oc({mode:`visible`,children:t},e.mode),t.return=e,e.child=t}function Oc(e,t){return e=_i(22,e,null,t),e.lanes=0,e}function kc(e,t,n){return Ga(t,e.child,null,n),e=Dc(t,t.pendingProps.children),e.flags|=2,t.memoizedState=null,e}function Ac(e,t,n){e.lanes|=t;var r=e.alternate;r!==null&&(r.lanes|=t),ia(e.return,t,n)}function jc(e,t,n,r,i,a){var o=e.memoizedState;o===null?e.memoizedState={isBackwards:t,rendering:null,renderingStartTime:0,last:r,tail:n,tailMode:i,treeForkCount:a}:(o.isBackwards=t,o.rendering=null,o.renderingStartTime=0,o.last=r,o.tail=n,o.tailMode=i,o.treeForkCount=a)}function Mc(e,t,n){var r=t.pendingProps,i=r.revealOrder,a=r.tail;r=r.children;var o=N.current,s=!!(o&2);if(s?(o=o&1|2,t.flags|=128):o&=1,O(N,o),cc(e,t,r,n),r=j?ji:0,!s&&e!==null&&e.flags&128)a:for(e=t.child;e!==null;){if(e.tag===13)e.memoizedState!==null&&Ac(e,n,t);else if(e.tag===19)Ac(e,n,t);else if(e.child!==null){e.child.return=e,e=e.child;continue}if(e===t)break a;for(;e.sibling===null;){if(e.return===null||e.return===t)break a;e=e.return}e.sibling.return=e.return,e=e.sibling}switch(i){case`forwards`:for(n=t.child,i=null;n!==null;)e=n.alternate,e!==null&&vo(e)===null&&(i=n),n=n.sibling;n=i,n===null?(i=t.child,t.child=null):(i=n.sibling,n.sibling=null),jc(t,!1,i,n,a,r);break;case`backwards`:case`unstable_legacy-backwards`:for(n=null,i=t.child,t.child=null;i!==null;){if(e=i.alternate,e!==null&&vo(e)===null){t.child=i;break}e=i.sibling,i.sibling=n,n=i,i=e}jc(t,!0,n,null,a,r);break;case`together`:jc(t,!1,null,null,void 0,r);break;default:t.memoizedState=null}return t.child}function Nc(e,t,n){if(e!==null&&(t.dependencies=e.dependencies),Kl|=t.lanes,(n&t.childLanes)===0){if(e!==null){if(oa(e,t,n,!1),(n&t.childLanes)===0)return null}else return null}if(e!==null&&t.child!==e.child)throw Error(s(153));if(t.child!==null){for(e=t.child,n=yi(e,e.pendingProps),t.child=n,n.return=t;e.sibling!==null;)e=e.sibling,n=n.sibling=yi(e,e.pendingProps),n.return=t;n.sibling=null}return t.child}function Pc(e,t){return(e.lanes&t)!==0||(e=e.dependencies,!!(e!==null&&sa(e)))}function Fc(e,t,n){switch(t.tag){case 3:ye(t,t.stateNode.containerInfo),na(t,M,e.memoizedState.cache),Xi();break;case 27:case 5:xe(t);break;case 4:ye(t,t.stateNode.containerInfo);break;case 10:na(t,t.type,t.memoizedProps.value);break;case 31:if(t.memoizedState!==null)return t.flags|=128,mo(t),null;break;case 13:var r=t.memoizedState;if(r!==null)return r.dehydrated===null?(n&t.child.childLanes)===0?(po(t),e=Nc(e,t,n),e===null?null:e.sibling):Ec(e,t,n):(po(t),t.flags|=128,null);po(t);break;case 19:var i=!!(e.flags&128);if(r=(n&t.childLanes)!==0,r||=(oa(e,t,n,!1),(n&t.childLanes)!==0),i){if(r)return Mc(e,t,n);t.flags|=128}if(i=t.memoizedState,i!==null&&(i.rendering=null,i.tail=null,i.lastEffect=null),O(N,N.current),r)break;return null;case 22:return t.lanes=0,fc(e,t,n,t.pendingProps);case 24:na(t,M,e.memoizedState.cache)}return Nc(e,t,n)}function Ic(e,t,n){if(e!==null){if(e.memoizedProps!==t.pendingProps)z=!0;else{if(!Pc(e,n)&&!(t.flags&128))return z=!1,Fc(e,t,n);z=!!(e.flags&131072)}}else z=!1,j&&t.flags&1048576&&Ri(t,ji,t.index);switch(t.lanes=0,t.tag){case 16:a:{var r=t.pendingProps;if(e=Fa(t.elementType),t.type=e,typeof e==`function`)vi(e)?(r=Zs(e,r),t.tag=1,t=xc(null,t,e,r,n)):(t.tag=0,t=yc(null,t,e,r,n));else{if(e!=null){var i=e.$$typeof;if(i===w){t.tag=11,t=lc(null,t,e,r,n);break a}if(i===ne){t.tag=14,t=uc(null,t,e,r,n);break a}}throw t=ce(e)||e,Error(s(306,t,``))}}return t;case 0:return yc(e,t,t.type,t.pendingProps,n);case 1:return r=t.type,i=Zs(r,t.pendingProps),xc(e,t,r,i,n);case 3:a:{if(ye(t,t.stateNode.containerInfo),e===null)throw Error(s(387));r=t.pendingProps;var a=t.memoizedState;i=a.element,Ya(e,t),no(t,r,null,n);var o=t.memoizedState;if(r=o.cache,na(t,M,r),r!==a.cache&&aa(t,[M],n,!0),to(),r=o.element,a.isDehydrated){if(a={element:r,isDehydrated:!1,cache:o.cache},t.updateQueue.baseState=a,t.memoizedState=a,t.flags&256){t=Sc(e,t,r,n);break a}if(r!==i){i=Di(Error(s(424)),t),Qi(i),t=Sc(e,t,r,n);break a}switch(e=t.stateNode.containerInfo,e.nodeType){case 9:e=e.body;break;default:e=e.nodeName===`HTML`?e.ownerDocument.body:e}for(A=cf(e.firstChild),Hi=t,j=!0,Ui=null,Wi=!0,n=Ka(t,null,r,n),t.child=n;n;)n.flags=n.flags&-3|4096,n=n.sibling}else{if(Xi(),r===i){t=Nc(e,t,n);break a}cc(e,t,r,n)}t=t.child}return t;case 26:return vc(e,t),e===null?(n=kf(t.type,null,t.pendingProps,null))?t.memoizedState=n:j||(n=t.type,e=t.pendingProps,r=Bd(_e.current).createElement(n),r[gt]=t,r[_t]=e,Pd(r,n,e),k(r),t.stateNode=r):t.memoizedState=kf(t.type,e.memoizedProps,t.pendingProps,e.memoizedState),null;case 27:return xe(t),e===null&&j&&(r=t.stateNode=ff(t.type,t.pendingProps,_e.current),Hi=t,Wi=!0,i=A,Zd(t.type)?(lf=i,A=cf(r.firstChild)):A=i),cc(e,t,t.pendingProps.children,n),vc(e,t),e===null&&(t.flags|=4194304),t.child;case 5:return e===null&&j&&((i=r=A)&&(r=tf(r,t.type,t.pendingProps,Wi),r===null?i=!1:(t.stateNode=r,Hi=t,A=cf(r.firstChild),Wi=!1,i=!0)),i||Ki(t)),xe(t),i=t.type,a=t.pendingProps,o=e===null?null:e.memoizedProps,r=a.children,Ud(i,a)?r=null:o!==null&&Ud(i,o)&&(t.flags|=32),t.memoizedState!==null&&(i=Oo(e,t,jo,null,null,n),Qf._currentValue=i),vc(e,t),cc(e,t,r,n),t.child;case 6:return e===null&&j&&((e=n=A)&&(n=nf(n,t.pendingProps,Wi),n===null?e=!1:(t.stateNode=n,Hi=t,A=null,e=!0)),e||Ki(t)),null;case 13:return Ec(e,t,n);case 4:return ye(t,t.stateNode.containerInfo),r=t.pendingProps,e===null?t.child=Ga(t,null,r,n):cc(e,t,r,n),t.child;case 11:return lc(e,t,t.type,t.pendingProps,n);case 7:return cc(e,t,t.pendingProps,n),t.child;case 8:return cc(e,t,t.pendingProps.children,n),t.child;case 12:return cc(e,t,t.pendingProps.children,n),t.child;case 10:return r=t.pendingProps,na(t,t.type,r.value),cc(e,t,r.children,n),t.child;case 9:return i=t.type._context,r=t.pendingProps.children,ca(t),i=la(i),r=r(i),t.flags|=1,cc(e,t,r,n),t.child;case 14:return uc(e,t,t.type,t.pendingProps,n);case 15:return dc(e,t,t.type,t.pendingProps,n);case 19:return Mc(e,t,n);case 31:return _c(e,t,n);case 22:return fc(e,t,n,t.pendingProps);case 24:return ca(t),r=la(M),e===null?(i=Ea(),i===null&&(i=G,a=ha(),i.pooledCache=a,a.refCount++,a!==null&&(i.pooledCacheLanes|=n),i=a),t.memoizedState={parent:r,cache:i},Ja(t),na(t,M,i)):((e.lanes&n)!==0&&(Ya(e,t),no(t,null,null,n),to()),i=e.memoizedState,a=t.memoizedState,i.parent===r?(r=a.cache,na(t,M,r),r!==i.cache&&aa(t,[M],n,!0)):(i={parent:r,cache:r},t.memoizedState=i,t.lanes===0&&(t.memoizedState=t.updateQueue.baseState=i),na(t,M,r))),cc(e,t,t.pendingProps.children,n),t.child;case 29:throw t.pendingProps}throw Error(s(156,t.tag))}function Lc(e){e.flags|=4}function Rc(e,t,n,r,i){if((t=!!(e.mode&32))&&(t=!1),t){if(e.flags|=16777216,(i&335544128)===i){if(e.stateNode.complete)e.flags|=8192;else if(wu())e.flags|=8192;else throw Ia=Ma,Aa}}else e.flags&=-16777217}function zc(e,t){if(t.type!==`stylesheet`||t.state.loading&4)e.flags&=-16777217;else if(e.flags|=16777216,!Wf(t)){if(wu())e.flags|=8192;else throw Ia=Ma,Aa}}function Bc(e,t){t!==null&&(e.flags|=4),e.flags&16384&&(t=e.tag===22?536870912:it(),e.lanes|=t,Xl|=t)}function Vc(e,t){if(!j)switch(e.tailMode){case`hidden`:t=e.tail;for(var n=null;t!==null;)t.alternate!==null&&(n=t),t=t.sibling;n===null?e.tail=null:n.sibling=null;break;case`collapsed`:n=e.tail;for(var r=null;n!==null;)n.alternate!==null&&(r=n),n=n.sibling;r===null?t||e.tail===null?e.tail=null:e.tail.sibling=null:r.sibling=null}}function B(e){var t=e.alternate!==null&&e.alternate.child===e.child,n=0,r=0;if(t)for(var i=e.child;i!==null;)n|=i.lanes|i.childLanes,r|=i.subtreeFlags&65011712,r|=i.flags&65011712,i.return=e,i=i.sibling;else for(i=e.child;i!==null;)n|=i.lanes|i.childLanes,r|=i.subtreeFlags,r|=i.flags,i.return=e,i=i.sibling;return e.subtreeFlags|=r,e.childLanes=n,t}function Hc(e,t,n){var r=t.pendingProps;switch(Bi(t),t.tag){case 16:case 15:case 0:case 11:case 7:case 8:case 12:case 9:case 14:return B(t),null;case 1:return B(t),null;case 3:return n=t.stateNode,r=null,e!==null&&(r=e.memoizedState.cache),t.memoizedState.cache!==r&&(t.flags|=2048),ra(M),be(),n.pendingContext&&(n.context=n.pendingContext,n.pendingContext=null),(e===null||e.child===null)&&(Yi(t)?Lc(t):e===null||e.memoizedState.isDehydrated&&!(t.flags&256)||(t.flags|=1024,Zi())),B(t),null;case 26:var i=t.type,a=t.memoizedState;return e===null?(Lc(t),a===null?(B(t),Rc(t,i,null,r,n)):(B(t),zc(t,a))):a?a===e.memoizedState?(B(t),t.flags&=-16777217):(Lc(t),B(t),zc(t,a)):(e=e.memoizedProps,e!==r&&Lc(t),B(t),Rc(t,i,e,r,n)),null;case 27:if(Se(t),n=_e.current,i=t.type,e!==null&&t.stateNode!=null)e.memoizedProps!==r&&Lc(t);else{if(!r){if(t.stateNode===null)throw Error(s(166));return B(t),null}e=he.current,Yi(t)?qi(t,e):(e=ff(i,r,n),t.stateNode=e,Lc(t))}return B(t),null;case 5:if(Se(t),i=t.type,e!==null&&t.stateNode!=null)e.memoizedProps!==r&&Lc(t);else{if(!r){if(t.stateNode===null)throw Error(s(166));return B(t),null}if(a=he.current,Yi(t))qi(t,a);else{var o=Bd(_e.current);switch(a){case 1:a=o.createElementNS(`http://www.w3.org/2000/svg`,i);break;case 2:a=o.createElementNS(`http://www.w3.org/1998/Math/MathML`,i);break;default:switch(i){case`svg`:a=o.createElementNS(`http://www.w3.org/2000/svg`,i);break;case`math`:a=o.createElementNS(`http://www.w3.org/1998/Math/MathML`,i);break;case`script`:a=o.createElement(`div`),a.innerHTML=`<script><\/script>`,a=a.removeChild(a.firstChild);break;case`select`:a=typeof r.is==`string`?o.createElement(`select`,{is:r.is}):o.createElement(`select`),r.multiple?a.multiple=!0:r.size&&(a.size=r.size);break;default:a=typeof r.is==`string`?o.createElement(i,{is:r.is}):o.createElement(i)}}a[gt]=t,a[_t]=r;a:for(o=t.child;o!==null;){if(o.tag===5||o.tag===6)a.appendChild(o.stateNode);else if(o.tag!==4&&o.tag!==27&&o.child!==null){o.child.return=o,o=o.child;continue}if(o===t)break a;for(;o.sibling===null;){if(o.return===null||o.return===t)break a;o=o.return}o.sibling.return=o.return,o=o.sibling}t.stateNode=a;a:switch(Pd(a,i,r),i){case`button`:case`input`:case`select`:case`textarea`:r=!!r.autoFocus;break a;case`img`:r=!0;break a;default:r=!1}r&&Lc(t)}}return B(t),Rc(t,t.type,e===null?null:e.memoizedProps,t.pendingProps,n),null;case 6:if(e&&t.stateNode!=null)e.memoizedProps!==r&&Lc(t);else{if(typeof r!=`string`&&t.stateNode===null)throw Error(s(166));if(e=_e.current,Yi(t)){if(e=t.stateNode,n=t.memoizedProps,r=null,i=Hi,i!==null)switch(i.tag){case 27:case 5:r=i.memoizedProps}e[gt]=t,e=!!(e.nodeValue===n||r!==null&&!0===r.suppressHydrationWarning||Md(e.nodeValue,n)),e||Ki(t,!0)}else e=Bd(e).createTextNode(r),e[gt]=t,t.stateNode=e}return B(t),null;case 31:if(n=t.memoizedState,e===null||e.memoizedState!==null){if(r=Yi(t),n!==null){if(e===null){if(!r)throw Error(s(318));if(e=t.memoizedState,e=e===null?null:e.dehydrated,!e)throw Error(s(557));e[gt]=t}else Xi(),!(t.flags&128)&&(t.memoizedState=null),t.flags|=4;B(t),e=!1}else n=Zi(),e!==null&&e.memoizedState!==null&&(e.memoizedState.hydrationErrors=n),e=!0;if(!e)return t.flags&256?(_o(t),t):(_o(t),null);if(t.flags&128)throw Error(s(558))}return B(t),null;case 13:if(r=t.memoizedState,e===null||e.memoizedState!==null&&e.memoizedState.dehydrated!==null){if(i=Yi(t),r!==null&&r.dehydrated!==null){if(e===null){if(!i)throw Error(s(318));if(i=t.memoizedState,i=i===null?null:i.dehydrated,!i)throw Error(s(317));i[gt]=t}else Xi(),!(t.flags&128)&&(t.memoizedState=null),t.flags|=4;B(t),i=!1}else i=Zi(),e!==null&&e.memoizedState!==null&&(e.memoizedState.hydrationErrors=i),i=!0;if(!i)return t.flags&256?(_o(t),t):(_o(t),null)}return _o(t),t.flags&128?(t.lanes=n,t):(n=r!==null,e=e!==null&&e.memoizedState!==null,n&&(r=t.child,i=null,r.alternate!==null&&r.alternate.memoizedState!==null&&r.alternate.memoizedState.cachePool!==null&&(i=r.alternate.memoizedState.cachePool.pool),a=null,r.memoizedState!==null&&r.memoizedState.cachePool!==null&&(a=r.memoizedState.cachePool.pool),a!==i&&(r.flags|=2048)),n!==e&&n&&(t.child.flags|=8192),Bc(t,t.updateQueue),B(t),null);case 4:return be(),e===null&&Sd(t.stateNode.containerInfo),B(t),null;case 10:return ra(t.type),B(t),null;case 19:if(me(N),r=t.memoizedState,r===null)return B(t),null;if(i=!!(t.flags&128),a=r.rendering,a===null){if(i)Vc(r,!1);else{if(Y!==0||e!==null&&e.flags&128)for(e=t.child;e!==null;){if(a=vo(e),a!==null){for(t.flags|=128,Vc(r,!1),e=a.updateQueue,t.updateQueue=e,Bc(t,e),t.subtreeFlags=0,e=n,n=t.child;n!==null;)bi(n,e),n=n.sibling;return O(N,N.current&1|2),j&&Li(t,r.treeForkCount),t.child}e=e.sibling}r.tail!==null&&Fe()>nu&&(t.flags|=128,i=!0,Vc(r,!1),t.lanes=4194304)}}else{if(!i){if(e=vo(a),e!==null){if(t.flags|=128,i=!0,e=e.updateQueue,t.updateQueue=e,Bc(t,e),Vc(r,!0),r.tail===null&&r.tailMode===`hidden`&&!a.alternate&&!j)return B(t),null}else 2*Fe()-r.renderingStartTime>nu&&n!==536870912&&(t.flags|=128,i=!0,Vc(r,!1),t.lanes=4194304)}r.isBackwards?(a.sibling=t.child,t.child=a):(e=r.last,e===null?t.child=a:e.sibling=a,r.last=a)}return r.tail===null?(B(t),null):(e=r.tail,r.rendering=e,r.tail=e.sibling,r.renderingStartTime=Fe(),e.sibling=null,n=N.current,O(N,i?n&1|2:n&1),j&&Li(t,r.treeForkCount),e);case 22:case 23:return _o(t),lo(),r=t.memoizedState!==null,e===null?r&&(t.flags|=8192):e.memoizedState!==null!==r&&(t.flags|=8192),r?n&536870912&&!(t.flags&128)&&(B(t),t.subtreeFlags&6&&(t.flags|=8192)):B(t),n=t.updateQueue,n!==null&&Bc(t,n.retryQueue),n=null,e!==null&&e.memoizedState!==null&&e.memoizedState.cachePool!==null&&(n=e.memoizedState.cachePool.pool),r=null,t.memoizedState!==null&&t.memoizedState.cachePool!==null&&(r=t.memoizedState.cachePool.pool),r!==n&&(t.flags|=2048),e!==null&&me(Ta),null;case 24:return n=null,e!==null&&(n=e.memoizedState.cache),t.memoizedState.cache!==n&&(t.flags|=2048),ra(M),B(t),null;case 25:return null;case 30:return null}throw Error(s(156,t.tag))}function Uc(e,t){switch(Bi(t),t.tag){case 1:return e=t.flags,e&65536?(t.flags=e&-65537|128,t):null;case 3:return ra(M),be(),e=t.flags,e&65536&&!(e&128)?(t.flags=e&-65537|128,t):null;case 26:case 27:case 5:return Se(t),null;case 31:if(t.memoizedState!==null){if(_o(t),t.alternate===null)throw Error(s(340));Xi()}return e=t.flags,e&65536?(t.flags=e&-65537|128,t):null;case 13:if(_o(t),e=t.memoizedState,e!==null&&e.dehydrated!==null){if(t.alternate===null)throw Error(s(340));Xi()}return e=t.flags,e&65536?(t.flags=e&-65537|128,t):null;case 19:return me(N),null;case 4:return be(),null;case 10:return ra(t.type),null;case 22:case 23:return _o(t),lo(),e!==null&&me(Ta),e=t.flags,e&65536?(t.flags=e&-65537|128,t):null;case 24:return ra(M),null;case 25:return null;default:return null}}function Wc(e,t){switch(Bi(t),t.tag){case 3:ra(M),be();break;case 26:case 27:case 5:Se(t);break;case 4:be();break;case 31:t.memoizedState!==null&&_o(t);break;case 13:_o(t);break;case 19:me(N);break;case 10:ra(t.type);break;case 22:case 23:_o(t),lo(),e!==null&&me(Ta);break;case 24:ra(M)}}function Gc(e,t){try{var n=t.updateQueue,r=n===null?null:n.lastEffect;if(r!==null){var i=r.next;n=i;do{if((n.tag&e)===e){r=void 0;var a=n.create,o=n.inst;r=a(),o.destroy=r}n=n.next}while(n!==i)}}catch(e){Z(t,t.return,e)}}function Kc(e,t,n){try{var r=t.updateQueue,i=r===null?null:r.lastEffect;if(i!==null){var a=i.next;r=a;do{if((r.tag&e)===e){var o=r.inst,s=o.destroy;if(s!==void 0){o.destroy=void 0,i=t;var c=n,l=s;try{l()}catch(e){Z(i,c,e)}}}r=r.next}while(r!==a)}}catch(e){Z(t,t.return,e)}}function qc(e){var t=e.updateQueue;if(t!==null){var n=e.stateNode;try{io(t,n)}catch(t){Z(e,e.return,t)}}}function Jc(e,t,n){n.props=Zs(e.type,e.memoizedProps),n.state=e.memoizedState;try{n.componentWillUnmount()}catch(n){Z(e,t,n)}}function Yc(e,t){try{var n=e.ref;if(n!==null){switch(e.tag){case 26:case 27:case 5:var r=e.stateNode;break;case 30:r=e.stateNode;break;default:r=e.stateNode}typeof n==`function`?e.refCleanup=n(r):n.current=r}}catch(n){Z(e,t,n)}}function Xc(e,t){var n=e.ref,r=e.refCleanup;if(n!==null){if(typeof r==`function`)try{r()}catch(n){Z(e,t,n)}finally{e.refCleanup=null,e=e.alternate,e!=null&&(e.refCleanup=null)}else if(typeof n==`function`)try{n(null)}catch(n){Z(e,t,n)}else n.current=null}}function Zc(e){var t=e.type,n=e.memoizedProps,r=e.stateNode;try{a:switch(t){case`button`:case`input`:case`select`:case`textarea`:n.autoFocus&&r.focus();break a;case`img`:n.src?r.src=n.src:n.srcSet&&(r.srcset=n.srcSet)}}catch(t){Z(e,e.return,t)}}function Qc(e,t,n){try{var r=e.stateNode;Fd(r,e.type,n,t),r[_t]=t}catch(t){Z(e,e.return,t)}}function $c(e){return e.tag===5||e.tag===3||e.tag===26||e.tag===27&&Zd(e.type)||e.tag===4}function el(e){a:for(;;){for(;e.sibling===null;){if(e.return===null||$c(e.return))return null;e=e.return}for(e.sibling.return=e.return,e=e.sibling;e.tag!==5&&e.tag!==6&&e.tag!==18;){if(e.tag===27&&Zd(e.type)||e.flags&2||e.child===null||e.tag===4)continue a;e.child.return=e,e=e.child}if(!(e.flags&2))return e.stateNode}}function tl(e,t,n){var r=e.tag;if(r===5||r===6)e=e.stateNode,t?(n.nodeType===9?n.body:n.nodeName===`HTML`?n.ownerDocument.body:n).insertBefore(e,t):(t=n.nodeType===9?n.body:n.nodeName===`HTML`?n.ownerDocument.body:n,t.appendChild(e),n=n._reactRootContainer,n!=null||t.onclick!==null||(t.onclick=ln));else if(r!==4&&(r===27&&Zd(e.type)&&(n=e.stateNode,t=null),e=e.child,e!==null))for(tl(e,t,n),e=e.sibling;e!==null;)tl(e,t,n),e=e.sibling}function nl(e,t,n){var r=e.tag;if(r===5||r===6)e=e.stateNode,t?n.insertBefore(e,t):n.appendChild(e);else if(r!==4&&(r===27&&Zd(e.type)&&(n=e.stateNode),e=e.child,e!==null))for(nl(e,t,n),e=e.sibling;e!==null;)nl(e,t,n),e=e.sibling}function rl(e){var t=e.stateNode,n=e.memoizedProps;try{for(var r=e.type,i=t.attributes;i.length;)t.removeAttributeNode(i[0]);Pd(t,r,n),t[gt]=e,t[_t]=n}catch(t){Z(e,e.return,t)}}var il=!1,V=!1,al=!1,ol=typeof WeakSet==`function`?WeakSet:Set,H=null;function sl(e,t){if(e=e.containerInfo,Rd=sp,e=Ir(e),Lr(e)){if(`selectionStart`in e)var n={start:e.selectionStart,end:e.selectionEnd};else a:{n=(n=e.ownerDocument)&&n.defaultView||window;var r=n.getSelection&&n.getSelection();if(r&&r.rangeCount!==0){n=r.anchorNode;var i=r.anchorOffset,a=r.focusNode;r=r.focusOffset;try{n.nodeType,a.nodeType}catch{n=null;break a}var o=0,c=-1,l=-1,u=0,d=0,f=e,p=null;b:for(;;){for(var m;f!==n||i!==0&&f.nodeType!==3||(c=o+i),f!==a||r!==0&&f.nodeType!==3||(l=o+r),f.nodeType===3&&(o+=f.nodeValue.length),(m=f.firstChild)!==null;)p=f,f=m;for(;;){if(f===e)break b;if(p===n&&++u===i&&(c=o),p===a&&++d===r&&(l=o),(m=f.nextSibling)!==null)break;f=p,p=f.parentNode}f=m}n=c===-1||l===-1?null:{start:c,end:l}}else n=null}n||={start:0,end:0}}else n=null;for(zd={focusedElem:e,selectionRange:n},sp=!1,H=t;H!==null;)if(t=H,e=t.child,t.subtreeFlags&1028&&e!==null)e.return=t,H=e;else for(;H!==null;){switch(t=H,a=t.alternate,e=t.flags,t.tag){case 0:if(e&4&&(e=t.updateQueue,e=e===null?null:e.events,e!==null))for(n=0;n<e.length;n++)i=e[n],i.ref.impl=i.nextImpl;break;case 11:case 15:break;case 1:if(e&1024&&a!==null){e=void 0,n=t,i=a.memoizedProps,a=a.memoizedState,r=n.stateNode;try{var h=Zs(n.type,i);e=r.getSnapshotBeforeUpdate(h,a),r.__reactInternalSnapshotBeforeUpdate=e}catch(e){Z(n,n.return,e)}}break;case 3:if(e&1024){if(e=t.stateNode.containerInfo,n=e.nodeType,n===9)ef(e);else if(n===1)switch(e.nodeName){case`HEAD`:case`HTML`:case`BODY`:ef(e);break;default:e.textContent=``}}break;case 5:case 26:case 27:case 6:case 4:case 17:break;default:if(e&1024)throw Error(s(163))}if(e=t.sibling,e!==null){e.return=t.return,H=e;break}H=t.return}}function cl(e,t,n){var r=n.flags;switch(n.tag){case 0:case 11:case 15:Sl(e,n),r&4&&Gc(5,n);break;case 1:if(Sl(e,n),r&4){if(e=n.stateNode,t===null)try{e.componentDidMount()}catch(e){Z(n,n.return,e)}else{var i=Zs(n.type,t.memoizedProps);t=t.memoizedState;try{e.componentDidUpdate(i,t,e.__reactInternalSnapshotBeforeUpdate)}catch(e){Z(n,n.return,e)}}}r&64&&qc(n),r&512&&Yc(n,n.return);break;case 3:if(Sl(e,n),r&64&&(e=n.updateQueue,e!==null)){if(t=null,n.child!==null)switch(n.child.tag){case 27:case 5:t=n.child.stateNode;break;case 1:t=n.child.stateNode}try{io(e,t)}catch(e){Z(n,n.return,e)}}break;case 27:t===null&&r&4&&rl(n);case 26:case 5:Sl(e,n),t===null&&r&4&&Zc(n),r&512&&Yc(n,n.return);break;case 12:Sl(e,n);break;case 31:Sl(e,n),r&4&&pl(e,n);break;case 13:Sl(e,n),r&4&&ml(e,n),r&64&&(e=n.memoizedState,e!==null&&(e=e.dehydrated,e!==null&&(n=Ju.bind(null,n),sf(e,n))));break;case 22:if(r=n.memoizedState!==null||il,!r){t=t!==null&&t.memoizedState!==null||V,i=il;var a=V;il=r,(V=t)&&!a?wl(e,n,!!(n.subtreeFlags&8772)):Sl(e,n),il=i,V=a}break;case 30:break;default:Sl(e,n)}}function ll(e){var t=e.alternate;t!==null&&(e.alternate=null,ll(t)),e.child=null,e.deletions=null,e.sibling=null,e.tag===5&&(t=e.stateNode,t!==null&&wt(t)),e.stateNode=null,e.return=null,e.dependencies=null,e.memoizedProps=null,e.memoizedState=null,e.pendingProps=null,e.stateNode=null,e.updateQueue=null}var U=null,ul=!1;function dl(e,t,n){for(n=n.child;n!==null;)fl(e,t,n),n=n.sibling}function fl(e,t,n){if(Ge&&typeof Ge.onCommitFiberUnmount==`function`)try{Ge.onCommitFiberUnmount(We,n)}catch{}switch(n.tag){case 26:V||Xc(n,t),dl(e,t,n),n.memoizedState?n.memoizedState.count--:n.stateNode&&(n=n.stateNode,n.parentNode.removeChild(n));break;case 27:V||Xc(n,t);var r=U,i=ul;Zd(n.type)&&(U=n.stateNode,ul=!1),dl(e,t,n),pf(n.stateNode),U=r,ul=i;break;case 5:V||Xc(n,t);case 6:if(r=U,i=ul,U=null,dl(e,t,n),U=r,ul=i,U!==null){if(ul)try{(U.nodeType===9?U.body:U.nodeName===`HTML`?U.ownerDocument.body:U).removeChild(n.stateNode)}catch(e){Z(n,t,e)}else try{U.removeChild(n.stateNode)}catch(e){Z(n,t,e)}}break;case 18:U!==null&&(ul?(e=U,Qd(e.nodeType===9?e.body:e.nodeName===`HTML`?e.ownerDocument.body:e,n.stateNode),Np(e)):Qd(U,n.stateNode));break;case 4:r=U,i=ul,U=n.stateNode.containerInfo,ul=!0,dl(e,t,n),U=r,ul=i;break;case 0:case 11:case 14:case 15:Kc(2,n,t),V||Kc(4,n,t),dl(e,t,n);break;case 1:V||(Xc(n,t),r=n.stateNode,typeof r.componentWillUnmount==`function`&&Jc(n,t,r)),dl(e,t,n);break;case 21:dl(e,t,n);break;case 22:V=(r=V)||n.memoizedState!==null,dl(e,t,n),V=r;break;default:dl(e,t,n)}}function pl(e,t){if(t.memoizedState===null&&(e=t.alternate,e!==null&&(e=e.memoizedState,e!==null))){e=e.dehydrated;try{Np(e)}catch(e){Z(t,t.return,e)}}}function ml(e,t){if(t.memoizedState===null&&(e=t.alternate,e!==null&&(e=e.memoizedState,e!==null&&(e=e.dehydrated,e!==null))))try{Np(e)}catch(e){Z(t,t.return,e)}}function hl(e){switch(e.tag){case 31:case 13:case 19:var t=e.stateNode;return t===null&&(t=e.stateNode=new ol),t;case 22:return e=e.stateNode,t=e._retryCache,t===null&&(t=e._retryCache=new ol),t;default:throw Error(s(435,e.tag))}}function gl(e,t){var n=hl(e);t.forEach(function(t){if(!n.has(t)){n.add(t);var r=Yu.bind(null,e,t);t.then(r,r)}})}function _l(e,t){var n=t.deletions;if(n!==null)for(var r=0;r<n.length;r++){var i=n[r],a=e,o=t,c=o;a:for(;c!==null;){switch(c.tag){case 27:if(Zd(c.type)){U=c.stateNode,ul=!1;break a}break;case 5:U=c.stateNode,ul=!1;break a;case 3:case 4:U=c.stateNode.containerInfo,ul=!0;break a}c=c.return}if(U===null)throw Error(s(160));fl(a,o,i),U=null,ul=!1,a=i.alternate,a!==null&&(a.return=null),i.return=null}if(t.subtreeFlags&13886)for(t=t.child;t!==null;)yl(t,e),t=t.sibling}var vl=null;function yl(e,t){var n=e.alternate,r=e.flags;switch(e.tag){case 0:case 11:case 14:case 15:_l(t,e),bl(e),r&4&&(Kc(3,e,e.return),Gc(3,e),Kc(5,e,e.return));break;case 1:_l(t,e),bl(e),r&512&&(V||n===null||Xc(n,n.return)),r&64&&il&&(e=e.updateQueue,e!==null&&(r=e.callbacks,r!==null&&(n=e.shared.hiddenCallbacks,e.shared.hiddenCallbacks=n===null?r:n.concat(r))));break;case 26:var i=vl;if(_l(t,e),bl(e),r&512&&(V||n===null||Xc(n,n.return)),r&4){var a=n===null?null:n.memoizedState;if(r=e.memoizedState,n===null){if(r===null){if(e.stateNode===null){a:{r=e.type,n=e.memoizedProps,i=i.ownerDocument||i;b:switch(r){case`title`:a=i.getElementsByTagName(`title`)[0],(!a||a[Ct]||a[gt]||a.namespaceURI===`http://www.w3.org/2000/svg`||a.hasAttribute(`itemprop`))&&(a=i.createElement(r),i.head.insertBefore(a,i.querySelector(`head > title`))),Pd(a,r,n),a[gt]=e,k(a),r=a;break a;case`link`:var o=Vf(`link`,`href`,i).get(r+(n.href||``));if(o){for(var c=0;c<o.length;c++)if(a=o[c],a.getAttribute(`href`)===(n.href==null||n.href===``?null:n.href)&&a.getAttribute(`rel`)===(n.rel==null?null:n.rel)&&a.getAttribute(`title`)===(n.title==null?null:n.title)&&a.getAttribute(`crossorigin`)===(n.crossOrigin==null?null:n.crossOrigin)){o.splice(c,1);break b}}a=i.createElement(r),Pd(a,r,n),i.head.appendChild(a);break;case`meta`:if(o=Vf(`meta`,`content`,i).get(r+(n.content||``))){for(c=0;c<o.length;c++)if(a=o[c],a.getAttribute(`content`)===(n.content==null?null:``+n.content)&&a.getAttribute(`name`)===(n.name==null?null:n.name)&&a.getAttribute(`property`)===(n.property==null?null:n.property)&&a.getAttribute(`http-equiv`)===(n.httpEquiv==null?null:n.httpEquiv)&&a.getAttribute(`charset`)===(n.charSet==null?null:n.charSet)){o.splice(c,1);break b}}a=i.createElement(r),Pd(a,r,n),i.head.appendChild(a);break;default:throw Error(s(468,r))}a[gt]=e,k(a),r=a}e.stateNode=r}else Hf(i,e.type,e.stateNode)}else e.stateNode=If(i,r,e.memoizedProps)}else a===r?r===null&&e.stateNode!==null&&Qc(e,e.memoizedProps,n.memoizedProps):(a===null?n.stateNode!==null&&(n=n.stateNode,n.parentNode.removeChild(n)):a.count--,r===null?Hf(i,e.type,e.stateNode):If(i,r,e.memoizedProps))}break;case 27:_l(t,e),bl(e),r&512&&(V||n===null||Xc(n,n.return)),n!==null&&r&4&&Qc(e,e.memoizedProps,n.memoizedProps);break;case 5:if(_l(t,e),bl(e),r&512&&(V||n===null||Xc(n,n.return)),e.flags&32){i=e.stateNode;try{en(i,``)}catch(t){Z(e,e.return,t)}}r&4&&e.stateNode!=null&&(i=e.memoizedProps,Qc(e,i,n===null?i:n.memoizedProps)),r&1024&&(al=!0);break;case 6:if(_l(t,e),bl(e),r&4){if(e.stateNode===null)throw Error(s(162));r=e.memoizedProps,n=e.stateNode;try{n.nodeValue=r}catch(t){Z(e,e.return,t)}}break;case 3:if(Bf=null,i=vl,vl=gf(t.containerInfo),_l(t,e),vl=i,bl(e),r&4&&n!==null&&n.memoizedState.isDehydrated)try{Np(t.containerInfo)}catch(t){Z(e,e.return,t)}al&&(al=!1,xl(e));break;case 4:r=vl,vl=gf(e.stateNode.containerInfo),_l(t,e),bl(e),vl=r;break;case 12:_l(t,e),bl(e);break;case 31:_l(t,e),bl(e),r&4&&(r=e.updateQueue,r!==null&&(e.updateQueue=null,gl(e,r)));break;case 13:_l(t,e),bl(e),e.child.flags&8192&&e.memoizedState!==null!=(n!==null&&n.memoizedState!==null)&&(eu=Fe()),r&4&&(r=e.updateQueue,r!==null&&(e.updateQueue=null,gl(e,r)));break;case 22:i=e.memoizedState!==null;var l=n!==null&&n.memoizedState!==null,u=il,d=V;if(il=u||i,V=d||l,_l(t,e),V=d,il=u,bl(e),r&8192)a:for(t=e.stateNode,t._visibility=i?t._visibility&-2:t._visibility|1,i&&(n===null||l||il||V||Cl(e)),n=null,t=e;;){if(t.tag===5||t.tag===26){if(n===null){l=n=t;try{if(a=l.stateNode,i)o=a.style,typeof o.setProperty==`function`?o.setProperty(`display`,`none`,`important`):o.display=`none`;else{c=l.stateNode;var f=l.memoizedProps.style,p=f!=null&&f.hasOwnProperty(`display`)?f.display:null;c.style.display=p==null||typeof p==`boolean`?``:(``+p).trim()}}catch(e){Z(l,l.return,e)}}}else if(t.tag===6){if(n===null){l=t;try{l.stateNode.nodeValue=i?``:l.memoizedProps}catch(e){Z(l,l.return,e)}}}else if(t.tag===18){if(n===null){l=t;try{var m=l.stateNode;i?$d(m,!0):$d(l.stateNode,!1)}catch(e){Z(l,l.return,e)}}}else if((t.tag!==22&&t.tag!==23||t.memoizedState===null||t===e)&&t.child!==null){t.child.return=t,t=t.child;continue}if(t===e)break a;for(;t.sibling===null;){if(t.return===null||t.return===e)break a;n===t&&(n=null),t=t.return}n===t&&(n=null),t.sibling.return=t.return,t=t.sibling}r&4&&(r=e.updateQueue,r!==null&&(n=r.retryQueue,n!==null&&(r.retryQueue=null,gl(e,n))));break;case 19:_l(t,e),bl(e),r&4&&(r=e.updateQueue,r!==null&&(e.updateQueue=null,gl(e,r)));break;case 30:break;case 21:break;default:_l(t,e),bl(e)}}function bl(e){var t=e.flags;if(t&2){try{for(var n,r=e.return;r!==null;){if($c(r)){n=r;break}r=r.return}if(n==null)throw Error(s(160));switch(n.tag){case 27:var i=n.stateNode;nl(e,el(e),i);break;case 5:var a=n.stateNode;n.flags&32&&(en(a,``),n.flags&=-33),nl(e,el(e),a);break;case 3:case 4:var o=n.stateNode.containerInfo;tl(e,el(e),o);break;default:throw Error(s(161))}}catch(t){Z(e,e.return,t)}e.flags&=-3}t&4096&&(e.flags&=-4097)}function xl(e){if(e.subtreeFlags&1024)for(e=e.child;e!==null;){var t=e;xl(t),t.tag===5&&t.flags&1024&&t.stateNode.reset(),e=e.sibling}}function Sl(e,t){if(t.subtreeFlags&8772)for(t=t.child;t!==null;)cl(e,t.alternate,t),t=t.sibling}function Cl(e){for(e=e.child;e!==null;){var t=e;switch(t.tag){case 0:case 11:case 14:case 15:Kc(4,t,t.return),Cl(t);break;case 1:Xc(t,t.return);var n=t.stateNode;typeof n.componentWillUnmount==`function`&&Jc(t,t.return,n),Cl(t);break;case 27:pf(t.stateNode);case 26:case 5:Xc(t,t.return),Cl(t);break;case 22:t.memoizedState===null&&Cl(t);break;case 30:Cl(t);break;default:Cl(t)}e=e.sibling}}function wl(e,t,n){for(n&&=!!(t.subtreeFlags&8772),t=t.child;t!==null;){var r=t.alternate,i=e,a=t,o=a.flags;switch(a.tag){case 0:case 11:case 15:wl(i,a,n),Gc(4,a);break;case 1:if(wl(i,a,n),r=a,i=r.stateNode,typeof i.componentDidMount==`function`)try{i.componentDidMount()}catch(e){Z(r,r.return,e)}if(r=a,i=r.updateQueue,i!==null){var s=r.stateNode;try{var c=i.shared.hiddenCallbacks;if(c!==null)for(i.shared.hiddenCallbacks=null,i=0;i<c.length;i++)ro(c[i],s)}catch(e){Z(r,r.return,e)}}n&&o&64&&qc(a),Yc(a,a.return);break;case 27:rl(a);case 26:case 5:wl(i,a,n),n&&r===null&&o&4&&Zc(a),Yc(a,a.return);break;case 12:wl(i,a,n);break;case 31:wl(i,a,n),n&&o&4&&pl(i,a);break;case 13:wl(i,a,n),n&&o&4&&ml(i,a);break;case 22:a.memoizedState===null&&wl(i,a,n),Yc(a,a.return);break;case 30:break;default:wl(i,a,n)}t=t.sibling}}function Tl(e,t){var n=null;e!==null&&e.memoizedState!==null&&e.memoizedState.cachePool!==null&&(n=e.memoizedState.cachePool.pool),e=null,t.memoizedState!==null&&t.memoizedState.cachePool!==null&&(e=t.memoizedState.cachePool.pool),e!==n&&(e!=null&&e.refCount++,n!=null&&ga(n))}function El(e,t){e=null,t.alternate!==null&&(e=t.alternate.memoizedState.cache),t=t.memoizedState.cache,t!==e&&(t.refCount++,e!=null&&ga(e))}function Dl(e,t,n,r){if(t.subtreeFlags&10256)for(t=t.child;t!==null;)Ol(e,t,n,r),t=t.sibling}function Ol(e,t,n,r){var i=t.flags;switch(t.tag){case 0:case 11:case 15:Dl(e,t,n,r),i&2048&&Gc(9,t);break;case 1:Dl(e,t,n,r);break;case 3:Dl(e,t,n,r),i&2048&&(e=null,t.alternate!==null&&(e=t.alternate.memoizedState.cache),t=t.memoizedState.cache,t!==e&&(t.refCount++,e!=null&&ga(e)));break;case 12:if(i&2048){Dl(e,t,n,r),e=t.stateNode;try{var a=t.memoizedProps,o=a.id,s=a.onPostCommit;typeof s==`function`&&s(o,t.alternate===null?`mount`:`update`,e.passiveEffectDuration,-0)}catch(e){Z(t,t.return,e)}}else Dl(e,t,n,r);break;case 31:Dl(e,t,n,r);break;case 13:Dl(e,t,n,r);break;case 23:break;case 22:a=t.stateNode,o=t.alternate,t.memoizedState===null?a._visibility&2?Dl(e,t,n,r):(a._visibility|=2,kl(e,t,n,r,!!(t.subtreeFlags&10256)||!1)):a._visibility&2?Dl(e,t,n,r):Al(e,t),i&2048&&Tl(o,t);break;case 24:Dl(e,t,n,r),i&2048&&El(t.alternate,t);break;default:Dl(e,t,n,r)}}function kl(e,t,n,r,i){for(i&&=!!(t.subtreeFlags&10256)||!1,t=t.child;t!==null;){var a=e,o=t,s=n,c=r,l=o.flags;switch(o.tag){case 0:case 11:case 15:kl(a,o,s,c,i),Gc(8,o);break;case 23:break;case 22:var u=o.stateNode;o.memoizedState===null?(u._visibility|=2,kl(a,o,s,c,i)):u._visibility&2?kl(a,o,s,c,i):Al(a,o),i&&l&2048&&Tl(o.alternate,o);break;case 24:kl(a,o,s,c,i),i&&l&2048&&El(o.alternate,o);break;default:kl(a,o,s,c,i)}t=t.sibling}}function Al(e,t){if(t.subtreeFlags&10256)for(t=t.child;t!==null;){var n=e,r=t,i=r.flags;switch(r.tag){case 22:Al(n,r),i&2048&&Tl(r.alternate,r);break;case 24:Al(n,r),i&2048&&El(r.alternate,r);break;default:Al(n,r)}t=t.sibling}}var jl=8192;function Ml(e,t,n){if(e.subtreeFlags&jl)for(e=e.child;e!==null;)Nl(e,t,n),e=e.sibling}function Nl(e,t,n){switch(e.tag){case 26:Ml(e,t,n),e.flags&jl&&e.memoizedState!==null&&Gf(n,vl,e.memoizedState,e.memoizedProps);break;case 5:Ml(e,t,n);break;case 3:case 4:var r=vl;vl=gf(e.stateNode.containerInfo),Ml(e,t,n),vl=r;break;case 22:e.memoizedState===null&&(r=e.alternate,r!==null&&r.memoizedState!==null?(r=jl,jl=16777216,Ml(e,t,n),jl=r):Ml(e,t,n));break;default:Ml(e,t,n)}}function Pl(e){var t=e.alternate;if(t!==null&&(e=t.child,e!==null)){t.child=null;do t=e.sibling,e.sibling=null,e=t;while(e!==null)}}function Fl(e){var t=e.deletions;if(e.flags&16){if(t!==null)for(var n=0;n<t.length;n++){var r=t[n];H=r,Rl(r,e)}Pl(e)}if(e.subtreeFlags&10256)for(e=e.child;e!==null;)Il(e),e=e.sibling}function Il(e){switch(e.tag){case 0:case 11:case 15:Fl(e),e.flags&2048&&Kc(9,e,e.return);break;case 3:Fl(e);break;case 12:Fl(e);break;case 22:var t=e.stateNode;e.memoizedState!==null&&t._visibility&2&&(e.return===null||e.return.tag!==13)?(t._visibility&=-3,Ll(e)):Fl(e);break;default:Fl(e)}}function Ll(e){var t=e.deletions;if(e.flags&16){if(t!==null)for(var n=0;n<t.length;n++){var r=t[n];H=r,Rl(r,e)}Pl(e)}for(e=e.child;e!==null;){switch(t=e,t.tag){case 0:case 11:case 15:Kc(8,t,t.return),Ll(t);break;case 22:n=t.stateNode,n._visibility&2&&(n._visibility&=-3,Ll(t));break;default:Ll(t)}e=e.sibling}}function Rl(e,t){for(;H!==null;){var n=H;switch(n.tag){case 0:case 11:case 15:Kc(8,n,t);break;case 23:case 22:if(n.memoizedState!==null&&n.memoizedState.cachePool!==null){var r=n.memoizedState.cachePool.pool;r!=null&&r.refCount++}break;case 24:ga(n.memoizedState.cache)}if(r=n.child,r!==null)r.return=n,H=r;else a:for(n=e;H!==null;){r=H;var i=r.sibling,a=r.return;if(ll(r),r===n){H=null;break a}if(i!==null){i.return=a,H=i;break a}H=a}}}var zl={getCacheForType:function(e){var t=la(M),n=t.data.get(e);return n===void 0&&(n=e(),t.data.set(e,n)),n},cacheSignal:function(){return la(M).controller.signal}},Bl=typeof WeakMap==`function`?WeakMap:Map,W=0,G=null,K=null,q=0,J=0,Vl=null,Hl=!1,Ul=!1,Wl=!1,Gl=0,Y=0,Kl=0,ql=0,Jl=0,Yl=0,Xl=0,Zl=null,Ql=null,$l=!1,eu=0,tu=0,nu=1/0,ru=null,iu=null,X=0,au=null,ou=null,su=0,cu=0,lu=null,uu=null,du=0,fu=null;function pu(){return W&2&&q!==0?q&-q:E.T===null?pt():dd()}function mu(){if(Yl===0){if(!(q&536870912)||j){var e=Qe;Qe<<=1,!(Qe&3932160)&&(Qe=262144),Yl=e}else Yl=536870912}return e=uo.current,e!==null&&(e.flags|=32),Yl}function hu(e,t,n){(e===G&&(J===2||J===9)||e.cancelPendingCommit!==null)&&(Su(e,0),yu(e,q,Yl,!1)),ot(e,n),(!(W&2)||e!==G)&&(e===G&&(!(W&2)&&(ql|=n),Y===4&&yu(e,q,Yl,!1)),rd(e))}function gu(e,t,n){if(W&6)throw Error(s(327));var r=!n&&!(t&127)&&(t&e.expiredLanes)===0||nt(e,t),i=r?Au(e,t):Ou(e,t,!0),a=r;do{if(i===0){Ul&&!r&&yu(e,t,0,!1);break}if(n=e.current.alternate,a&&!vu(n)){i=Ou(e,t,!1),a=!1;continue}if(i===2){if(a=t,e.errorRecoveryDisabledLanes&a)var o=0;else o=e.pendingLanes&-536870913,o=o===0?o&536870912?536870912:0:o;if(o!==0){t=o;a:{var c=e;i=Zl;var l=c.current.memoizedState.isDehydrated;if(l&&(Su(c,o).flags|=256),o=Ou(c,o,!1),o!==2){if(Wl&&!l){c.errorRecoveryDisabledLanes|=a,ql|=a,i=4;break a}a=Ql,Ql=i,a!==null&&(Ql===null?Ql=a:Ql.push.apply(Ql,a))}i=o}if(a=!1,i!==2)continue}}if(i===1){Su(e,0),yu(e,t,0,!0);break}a:{switch(r=e,a=i,a){case 0:case 1:throw Error(s(345));case 4:if((t&4194048)!==t)break;case 6:yu(r,t,Yl,!Hl);break a;case 2:Ql=null;break;case 3:case 5:break;default:throw Error(s(329))}if((t&62914560)===t&&(i=eu+300-Fe(),10<i)){if(yu(r,t,Yl,!Hl),tt(r,0,!0)!==0)break a;su=t,r.timeoutHandle=Kd(_u.bind(null,r,n,Ql,ru,$l,t,Yl,ql,Xl,Hl,a,`Throttled`,-0,0),i);break a}_u(r,n,Ql,ru,$l,t,Yl,ql,Xl,Hl,a,null,-0,0)}break}while(1);rd(e)}function _u(e,t,n,r,i,a,o,s,c,l,u,d,f,p){if(e.timeoutHandle=-1,d=t.subtreeFlags,d&8192||(d&16785408)==16785408){d={stylesheets:null,count:0,imgCount:0,imgBytes:0,suspenseyImages:[],waitingForImages:!0,waitingForViewTransition:!1,unsuspend:ln},Nl(t,a,d);var m=(a&62914560)===a?eu-Fe():(a&4194048)===a?tu-Fe():0;if(m=qf(d,m),m!==null){su=a,e.cancelPendingCommit=m(Lu.bind(null,e,t,a,n,r,i,o,s,c,u,d,null,f,p)),yu(e,a,o,!l);return}}Lu(e,t,a,n,r,i,o,s,c)}function vu(e){for(var t=e;;){var n=t.tag;if((n===0||n===11||n===15)&&t.flags&16384&&(n=t.updateQueue,n!==null&&(n=n.stores,n!==null)))for(var r=0;r<n.length;r++){var i=n[r],a=i.getSnapshot;i=i.value;try{if(!jr(a(),i))return!1}catch{return!1}}if(n=t.child,t.subtreeFlags&16384&&n!==null)n.return=t,t=n;else{if(t===e)break;for(;t.sibling===null;){if(t.return===null||t.return===e)return!0;t=t.return}t.sibling.return=t.return,t=t.sibling}}return!0}function yu(e,t,n,r){t&=~Jl,t&=~ql,e.suspendedLanes|=t,e.pingedLanes&=~t,r&&(e.warmLanes|=t),r=e.expirationTimes;for(var i=t;0<i;){var a=31-qe(i),o=1<<a;r[a]=-1,i&=~o}n!==0&&ct(e,n,t)}function bu(){return W&6?!0:(id(0,!1),!1)}function xu(){if(K!==null){if(J===0)var e=K.return;else e=K,ta=ea=null,Po(e),za=null,Ba=0,e=K;for(;e!==null;)Wc(e.alternate,e),e=e.return;K=null}}function Su(e,t){var n=e.timeoutHandle;n!==-1&&(e.timeoutHandle=-1,qd(n)),n=e.cancelPendingCommit,n!==null&&(e.cancelPendingCommit=null,n()),su=0,xu(),G=e,K=n=yi(e.current,null),q=t,J=0,Vl=null,Hl=!1,Ul=nt(e,t),Wl=!1,Xl=Yl=Jl=ql=Kl=Y=0,Ql=Zl=null,$l=!1,t&8&&(t|=t&32);var r=e.entangledLanes;if(r!==0)for(e=e.entanglements,r&=t;0<r;){var i=31-qe(r),a=1<<i;t|=e[i],r&=~a}return Gl=t,li(),n}function Cu(e,t){P=null,E.H=Us,t===ka||t===ja?(t=La(),J=3):t===Aa?(t=La(),J=4):J=t===sc?8:typeof t==`object`&&t&&typeof t.then==`function`?6:1,Vl=t,K===null&&(Y=1,tc(e,Di(t,e.current)))}function wu(){var e=uo.current;return e===null?!0:(q&4194048)===q?fo===null:(q&62914560)===q||q&536870912?e===fo:!1}function Tu(){var e=E.H;return E.H=Us,e===null?Us:e}function Eu(){var e=E.A;return E.A=zl,e}function Du(){Y=4,Hl||(q&4194048)!==q&&uo.current!==null||(Ul=!0),!(Kl&134217727)&&!(ql&134217727)||G===null||yu(G,q,Yl,!1)}function Ou(e,t,n){var r=W;W|=2;var i=Tu(),a=Eu();(G!==e||q!==t)&&(ru=null,Su(e,t)),t=!1;var o=Y;a:do try{if(J!==0&&K!==null){var s=K,c=Vl;switch(J){case 8:xu(),o=6;break a;case 3:case 2:case 9:case 6:uo.current===null&&(t=!0);var l=J;if(J=0,Vl=null,Pu(e,s,c,l),n&&Ul){o=0;break a}break;default:l=J,J=0,Vl=null,Pu(e,s,c,l)}}ku(),o=Y;break}catch(t){Cu(e,t)}while(1);return t&&e.shellSuspendCounter++,ta=ea=null,W=r,E.H=i,E.A=a,K===null&&(G=null,q=0,li()),o}function ku(){for(;K!==null;)Mu(K)}function Au(e,t){var n=W;W|=2;var r=Tu(),i=Eu();G!==e||q!==t?(ru=null,nu=Fe()+500,Su(e,t)):Ul=nt(e,t);a:do try{if(J!==0&&K!==null){t=K;var a=Vl;b:switch(J){case 1:J=0,Vl=null,Pu(e,t,a,1);break;case 2:case 9:if(Na(a)){J=0,Vl=null,Nu(t);break}t=function(){J!==2&&J!==9||G!==e||(J=7),rd(e)},a.then(t,t);break a;case 3:J=7;break a;case 4:J=5;break a;case 7:Na(a)?(J=0,Vl=null,Nu(t)):(J=0,Vl=null,Pu(e,t,a,7));break;case 5:var o=null;switch(K.tag){case 26:o=K.memoizedState;case 5:case 27:var c=K;if(o?Wf(o):c.stateNode.complete){J=0,Vl=null;var l=c.sibling;if(l!==null)K=l;else{var u=c.return;u===null?K=null:(K=u,Fu(u))}break b}}J=0,Vl=null,Pu(e,t,a,5);break;case 6:J=0,Vl=null,Pu(e,t,a,6);break;case 8:xu(),Y=6;break a;default:throw Error(s(462))}}ju();break}catch(t){Cu(e,t)}while(1);return ta=ea=null,E.H=r,E.A=i,W=n,K===null?(G=null,q=0,li(),Y):0}function ju(){for(;K!==null&&!Ne();)Mu(K)}function Mu(e){var t=Ic(e.alternate,e,Gl);e.memoizedProps=e.pendingProps,t===null?Fu(e):K=t}function Nu(e){var t=e,n=t.alternate;switch(t.tag){case 15:case 0:t=bc(n,t,t.pendingProps,t.type,void 0,q);break;case 11:t=bc(n,t,t.pendingProps,t.type.render,t.ref,q);break;case 5:Po(t);default:Wc(n,t),t=K=bi(t,Gl),t=Ic(n,t,Gl)}e.memoizedProps=e.pendingProps,t===null?Fu(e):K=t}function Pu(e,t,n,r){ta=ea=null,Po(t),za=null,Ba=0;var i=t.return;try{if(oc(e,i,t,n,q)){Y=1,tc(e,Di(n,e.current)),K=null;return}}catch(t){if(i!==null)throw K=i,t;Y=1,tc(e,Di(n,e.current)),K=null;return}t.flags&32768?(j||r===1?e=!0:Ul||q&536870912?e=!1:(Hl=e=!0,(r===2||r===9||r===3||r===6)&&(r=uo.current,r!==null&&r.tag===13&&(r.flags|=16384))),Iu(t,e)):Fu(t)}function Fu(e){var t=e;do{if(t.flags&32768){Iu(t,Hl);return}e=t.return;var n=Hc(t.alternate,t,Gl);if(n!==null){K=n;return}if(t=t.sibling,t!==null){K=t;return}K=t=e}while(t!==null);Y===0&&(Y=5)}function Iu(e,t){do{var n=Uc(e.alternate,e);if(n!==null){n.flags&=32767,K=n;return}if(n=e.return,n!==null&&(n.flags|=32768,n.subtreeFlags=0,n.deletions=null),!t&&(e=e.sibling,e!==null)){K=e;return}K=e=n}while(e!==null);Y=6,K=null}function Lu(e,t,n,r,i,a,o,c,l){e.cancelPendingCommit=null;do Hu();while(X!==0);if(W&6)throw Error(s(327));if(t!==null){if(t===e.current)throw Error(s(177));if(a=t.lanes|t.childLanes,a|=ci,st(e,n,a,o,c,l),e===G&&(K=G=null,q=0),ou=t,au=e,su=n,cu=a,lu=i,uu=r,t.subtreeFlags&10256||t.flags&10256?(e.callbackNode=null,e.callbackPriority=0,Xu(ze,function(){return Uu(),null})):(e.callbackNode=null,e.callbackPriority=0),r=!!(t.flags&13878),t.subtreeFlags&13878||r){r=E.T,E.T=null,i=D.p,D.p=2,o=W,W|=4;try{sl(e,t,n)}finally{W=o,D.p=i,E.T=r}}X=1,Ru(),zu(),Bu()}}function Ru(){if(X===1){X=0;var e=au,t=ou,n=!!(t.flags&13878);if(t.subtreeFlags&13878||n){n=E.T,E.T=null;var r=D.p;D.p=2;var i=W;W|=4;try{yl(t,e);var a=zd,o=Ir(e.containerInfo),s=a.focusedElem,c=a.selectionRange;if(o!==s&&s&&s.ownerDocument&&Fr(s.ownerDocument.documentElement,s)){if(c!==null&&Lr(s)){var l=c.start,u=c.end;if(u===void 0&&(u=l),`selectionStart`in s)s.selectionStart=l,s.selectionEnd=Math.min(u,s.value.length);else{var d=s.ownerDocument||document,f=d&&d.defaultView||window;if(f.getSelection){var p=f.getSelection(),m=s.textContent.length,h=Math.min(c.start,m),g=c.end===void 0?h:Math.min(c.end,m);!p.extend&&h>g&&(o=g,g=h,h=o);var _=Pr(s,h),v=Pr(s,g);if(_&&v&&(p.rangeCount!==1||p.anchorNode!==_.node||p.anchorOffset!==_.offset||p.focusNode!==v.node||p.focusOffset!==v.offset)){var y=d.createRange();y.setStart(_.node,_.offset),p.removeAllRanges(),h>g?(p.addRange(y),p.extend(v.node,v.offset)):(y.setEnd(v.node,v.offset),p.addRange(y))}}}}for(d=[],p=s;p=p.parentNode;)p.nodeType===1&&d.push({element:p,left:p.scrollLeft,top:p.scrollTop});for(typeof s.focus==`function`&&s.focus(),s=0;s<d.length;s++){var b=d[s];b.element.scrollLeft=b.left,b.element.scrollTop=b.top}}sp=!!Rd,zd=Rd=null}finally{W=i,D.p=r,E.T=n}}e.current=t,X=2}}function zu(){if(X===2){X=0;var e=au,t=ou,n=!!(t.flags&8772);if(t.subtreeFlags&8772||n){n=E.T,E.T=null;var r=D.p;D.p=2;var i=W;W|=4;try{cl(e,t.alternate,t)}finally{W=i,D.p=r,E.T=n}}X=3}}function Bu(){if(X===4||X===3){X=0,Pe();var e=au,t=ou,n=su,r=uu;t.subtreeFlags&10256||t.flags&10256?X=5:(X=0,ou=au=null,Vu(e,e.pendingLanes));var i=e.pendingLanes;if(i===0&&(iu=null),ft(n),t=t.stateNode,Ge&&typeof Ge.onCommitFiberRoot==`function`)try{Ge.onCommitFiberRoot(We,t,void 0,(t.current.flags&128)==128)}catch{}if(r!==null){t=E.T,i=D.p,D.p=2,E.T=null;try{for(var a=e.onRecoverableError,o=0;o<r.length;o++){var s=r[o];a(s.value,{componentStack:s.stack})}}finally{E.T=t,D.p=i}}su&3&&Hu(),rd(e),i=e.pendingLanes,n&261930&&i&42?e===fu?du++:(du=0,fu=e):du=0,id(0,!1)}}function Vu(e,t){(e.pooledCacheLanes&=t)===0&&(t=e.pooledCache,t!=null&&(e.pooledCache=null,ga(t)))}function Hu(){return Ru(),zu(),Bu(),Uu()}function Uu(){if(X!==5)return!1;var e=au,t=cu;cu=0;var n=ft(su),r=E.T,i=D.p;try{D.p=32>n?32:n,E.T=null,n=lu,lu=null;var a=au,o=su;if(X=0,ou=au=null,su=0,W&6)throw Error(s(331));var c=W;if(W|=4,Il(a.current),Ol(a,a.current,o,n),W=c,id(0,!1),Ge&&typeof Ge.onPostCommitFiberRoot==`function`)try{Ge.onPostCommitFiberRoot(We,a)}catch{}return!0}finally{D.p=i,E.T=r,Vu(e,t)}}function Wu(e,t,n){t=Di(n,t),t=rc(e.stateNode,t,2),e=Za(e,t,2),e!==null&&(ot(e,2),rd(e))}function Z(e,t,n){if(e.tag===3)Wu(e,e,n);else for(;t!==null;){if(t.tag===3){Wu(t,e,n);break}if(t.tag===1){var r=t.stateNode;if(typeof t.type.getDerivedStateFromError==`function`||typeof r.componentDidCatch==`function`&&(iu===null||!iu.has(r))){e=Di(n,e),n=ic(2),r=Za(t,n,2),r!==null&&(ac(n,r,t,e),ot(r,2),rd(r));break}}t=t.return}}function Gu(e,t,n){var r=e.pingCache;if(r===null){r=e.pingCache=new Bl;var i=new Set;r.set(t,i)}else i=r.get(t),i===void 0&&(i=new Set,r.set(t,i));i.has(n)||(Wl=!0,i.add(n),e=Ku.bind(null,e,t,n),t.then(e,e))}function Ku(e,t,n){var r=e.pingCache;r!==null&&r.delete(t),e.pingedLanes|=e.suspendedLanes&n,e.warmLanes&=~n,G===e&&(q&n)===n&&(Y===4||Y===3&&(q&62914560)===q&&300>Fe()-eu?!(W&2)&&Su(e,0):Jl|=n,Xl===q&&(Xl=0)),rd(e)}function qu(e,t){t===0&&(t=it()),e=fi(e,t),e!==null&&(ot(e,t),rd(e))}function Ju(e){var t=e.memoizedState,n=0;t!==null&&(n=t.retryLane),qu(e,n)}function Yu(e,t){var n=0;switch(e.tag){case 31:case 13:var r=e.stateNode,i=e.memoizedState;i!==null&&(n=i.retryLane);break;case 19:r=e.stateNode;break;case 22:r=e.stateNode._retryCache;break;default:throw Error(s(314))}r!==null&&r.delete(t),qu(e,n)}function Xu(e,t){return je(e,t)}var Zu=null,Qu=null,$u=!1,ed=!1,td=!1,nd=0;function rd(e){e!==Qu&&e.next===null&&(Qu===null?Zu=Qu=e:Qu=Qu.next=e),ed=!0,$u||($u=!0,ud())}function id(e,t){if(!td&&ed){td=!0;do for(var n=!1,r=Zu;r!==null;){if(!t){if(e!==0){var i=r.pendingLanes;if(i===0)var a=0;else{var o=r.suspendedLanes,s=r.pingedLanes;a=(1<<31-qe(42|e)+1)-1,a&=i&~(o&~s),a=a&201326741?a&201326741|1:a?a|2:0}a!==0&&(n=!0,ld(r,a))}else a=q,a=tt(r,r===G?a:0,r.cancelPendingCommit!==null||r.timeoutHandle!==-1),!(a&3)||nt(r,a)||(n=!0,ld(r,a))}r=r.next}while(n);td=!1}}function ad(){od()}function od(){ed=$u=!1;var e=0;nd!==0&&Gd()&&(e=nd);for(var t=Fe(),n=null,r=Zu;r!==null;){var i=r.next,a=sd(r,t);a===0?(r.next=null,n===null?Zu=i:n.next=i,i===null&&(Qu=n)):(n=r,(e!==0||a&3)&&(ed=!0)),r=i}X!==0&&X!==5||id(e,!1),nd!==0&&(nd=0)}function sd(e,t){for(var n=e.suspendedLanes,r=e.pingedLanes,i=e.expirationTimes,a=e.pendingLanes&-62914561;0<a;){var o=31-qe(a),s=1<<o,c=i[o];c===-1?((s&n)===0||(s&r)!==0)&&(i[o]=rt(s,t)):c<=t&&(e.expiredLanes|=s),a&=~s}if(t=G,n=q,n=tt(e,e===t?n:0,e.cancelPendingCommit!==null||e.timeoutHandle!==-1),r=e.callbackNode,n===0||e===t&&(J===2||J===9)||e.cancelPendingCommit!==null)return r!==null&&r!==null&&Me(r),e.callbackNode=null,e.callbackPriority=0;if(!(n&3)||nt(e,n)){if(t=n&-n,t===e.callbackPriority)return t;switch(r!==null&&Me(r),ft(n)){case 2:case 8:n=Re;break;case 32:n=ze;break;case 268435456:n=Ve;break;default:n=ze}return r=cd.bind(null,e),n=je(n,r),e.callbackPriority=t,e.callbackNode=n,t}return r!==null&&r!==null&&Me(r),e.callbackPriority=2,e.callbackNode=null,2}function cd(e,t){if(X!==0&&X!==5)return e.callbackNode=null,e.callbackPriority=0,null;var n=e.callbackNode;if(Hu()&&e.callbackNode!==n)return null;var r=q;return r=tt(e,e===G?r:0,e.cancelPendingCommit!==null||e.timeoutHandle!==-1),r===0?null:(gu(e,r,t),sd(e,Fe()),e.callbackNode!=null&&e.callbackNode===n?cd.bind(null,e):null)}function ld(e,t){if(Hu())return null;gu(e,t,!0)}function ud(){Yd(function(){W&6?je(Le,ad):od()})}function dd(){if(nd===0){var e=ya;e===0&&(e=Ze,Ze<<=1,!(Ze&261888)&&(Ze=256)),nd=e}return nd}function fd(e){return e==null||typeof e==`symbol`||typeof e==`boolean`?null:typeof e==`function`?e:cn(``+e)}function pd(e,t){var n=t.ownerDocument.createElement(`input`);return n.name=t.name,n.value=t.value,e.id&&n.setAttribute(`form`,e.id),t.parentNode.insertBefore(n,t),e=new FormData(e),n.parentNode.removeChild(n),e}function md(e,t,n,r,i){if(t===`submit`&&n&&n.stateNode===i){var a=fd((i[_t]||null).action),o=r.submitter;o&&(t=(t=o[_t]||null)?fd(t.formAction):o.getAttribute(`formAction`),t!==null&&(a=t,o=null));var s=new An(`action`,`action`,null,r,i);e.push({event:s,listeners:[{instance:null,listener:function(){if(r.defaultPrevented){if(nd!==0){var e=o?pd(i,o):new FormData(i);ks(n,{pending:!0,data:e,method:i.method,action:a},null,e)}}else typeof a==`function`&&(s.preventDefault(),e=o?pd(i,o):new FormData(i),ks(n,{pending:!0,data:e,method:i.method,action:a},a,e))},currentTarget:i}]})}}for(var hd=0;hd<ri.length;hd++){var gd=ri[hd];ii(gd.toLowerCase(),`on`+(gd[0].toUpperCase()+gd.slice(1)))}ii(Yr,`onAnimationEnd`),ii(Xr,`onAnimationIteration`),ii(Zr,`onAnimationStart`),ii(`dblclick`,`onDoubleClick`),ii(`focusin`,`onFocus`),ii(`focusout`,`onBlur`),ii(Qr,`onTransitionRun`),ii($r,`onTransitionStart`),ii(ei,`onTransitionCancel`),ii(ti,`onTransitionEnd`),Mt(`onMouseEnter`,[`mouseout`,`mouseover`]),Mt(`onMouseLeave`,[`mouseout`,`mouseover`]),Mt(`onPointerEnter`,[`pointerout`,`pointerover`]),Mt(`onPointerLeave`,[`pointerout`,`pointerover`]),jt(`onChange`,`change click focusin focusout input keydown keyup selectionchange`.split(` `)),jt(`onSelect`,`focusout contextmenu dragend focusin keydown keyup mousedown mouseup selectionchange`.split(` `)),jt(`onBeforeInput`,[`compositionend`,`keypress`,`textInput`,`paste`]),jt(`onCompositionEnd`,`compositionend focusout keydown keypress keyup mousedown`.split(` `)),jt(`onCompositionStart`,`compositionstart focusout keydown keypress keyup mousedown`.split(` `)),jt(`onCompositionUpdate`,`compositionupdate focusout keydown keypress keyup mousedown`.split(` `));var _d=`abort canplay canplaythrough durationchange emptied encrypted ended error loadeddata loadedmetadata loadstart pause play playing progress ratechange resize seeked seeking stalled suspend timeupdate volumechange waiting`.split(` `),vd=new Set(`beforetoggle cancel close invalid load scroll scrollend toggle`.split(` `).concat(_d));function yd(e,t){t=!!(t&4);for(var n=0;n<e.length;n++){var r=e[n],i=r.event;r=r.listeners;a:{var a=void 0;if(t)for(var o=r.length-1;0<=o;o--){var s=r[o],c=s.instance,l=s.currentTarget;if(s=s.listener,c!==a&&i.isPropagationStopped())break a;a=s,i.currentTarget=l;try{a(i)}catch(e){ai(e)}i.currentTarget=null,a=c}else for(o=0;o<r.length;o++){if(s=r[o],c=s.instance,l=s.currentTarget,s=s.listener,c!==a&&i.isPropagationStopped())break a;a=s,i.currentTarget=l;try{a(i)}catch(e){ai(e)}i.currentTarget=null,a=c}}}}function Q(e,t){var n=t[yt];n===void 0&&(n=t[yt]=new Set);var r=e+`__bubble`;n.has(r)||(Cd(t,e,2,!1),n.add(r))}function bd(e,t,n){var r=0;t&&(r|=4),Cd(n,e,r,t)}var xd=`_reactListening`+Math.random().toString(36).slice(2);function Sd(e){if(!e[xd]){e[xd]=!0,kt.forEach(function(t){t!==`selectionchange`&&(vd.has(t)||bd(t,!1,e),bd(t,!0,e))});var t=e.nodeType===9?e:e.ownerDocument;t===null||t[xd]||(t[xd]=!0,bd(`selectionchange`,!1,t))}}function Cd(e,t,n,r){switch(mp(t)){case 2:var i=cp;break;case 8:i=lp;break;default:i=up}n=i.bind(null,t,n,e),i=void 0,!yn||t!==`touchstart`&&t!==`touchmove`&&t!==`wheel`||(i=!0),r?i===void 0?e.addEventListener(t,n,!0):e.addEventListener(t,n,{capture:!0,passive:i}):i===void 0?e.addEventListener(t,n,!1):e.addEventListener(t,n,{passive:i})}function wd(e,t,n,r,i){var a=r;if(!(t&1)&&!(t&2)&&r!==null)a:for(;;){if(r===null)return;var o=r.tag;if(o===3||o===4){var s=r.stateNode.containerInfo;if(s===i)break;if(o===4)for(o=r.return;o!==null;){var c=o.tag;if((c===3||c===4)&&o.stateNode.containerInfo===i)return;o=o.return}for(;s!==null;){if(o=Tt(s),o===null)return;if(c=o.tag,c===5||c===6||c===26||c===27){r=a=o;continue a}s=s.parentNode}}r=r.return}gn(function(){var r=a,i=dn(n),o=[];a:{var s=ni.get(e);if(s!==void 0){var c=An,u=e;switch(e){case`keypress`:if(Tn(n)===0)break a;case`keydown`:case`keyup`:c=Jn;break;case`focusin`:u=`focus`,c=zn;break;case`focusout`:u=`blur`,c=zn;break;case`beforeblur`:case`afterblur`:c=zn;break;case`click`:if(n.button===2)break a;case`auxclick`:case`dblclick`:case`mousedown`:case`mousemove`:case`mouseup`:case`mouseout`:case`mouseover`:case`contextmenu`:c=Ln;break;case`drag`:case`dragend`:case`dragenter`:case`dragexit`:case`dragleave`:case`dragover`:case`dragstart`:case`drop`:c=Rn;break;case`touchcancel`:case`touchend`:case`touchmove`:case`touchstart`:c=Xn;break;case Yr:case Xr:case Zr:c=Bn;break;case ti:c=Zn;break;case`scroll`:case`scrollend`:c=Mn;break;case`wheel`:c=Qn;break;case`copy`:case`cut`:case`paste`:c=Vn;break;case`gotpointercapture`:case`lostpointercapture`:case`pointercancel`:case`pointerdown`:case`pointermove`:case`pointerout`:case`pointerover`:case`pointerup`:c=Yn;break;case`toggle`:case`beforetoggle`:c=$n}var d=!!(t&4),f=!d&&(e===`scroll`||e===`scrollend`),p=d?s===null?null:s+`Capture`:s;d=[];for(var m=r,h;m!==null;){var g=m;if(h=g.stateNode,g=g.tag,g!==5&&g!==26&&g!==27||h===null||p===null||(g=_n(m,p),g!=null&&d.push(Td(m,g,h))),f)break;m=m.return}0<d.length&&(s=new c(s,u,null,n,i),o.push({event:s,listeners:d}))}}if(!(t&7)){a:{if(s=e===`mouseover`||e===`pointerover`,c=e===`mouseout`||e===`pointerout`,s&&n!==un&&(u=n.relatedTarget||n.fromElement)&&(Tt(u)||u[vt]))break a;if((c||s)&&(s=i.window===i?i:(s=i.ownerDocument)?s.defaultView||s.parentWindow:window,c?(u=n.relatedTarget||n.toElement,c=r,u=u?Tt(u):null,u!==null&&(f=l(u),d=u.tag,u!==f||d!==5&&d!==27&&d!==6)&&(u=null)):(c=null,u=r),c!==u)){if(d=Ln,g=`onMouseLeave`,p=`onMouseEnter`,m=`mouse`,(e===`pointerout`||e===`pointerover`)&&(d=Yn,g=`onPointerLeave`,p=`onPointerEnter`,m=`pointer`),f=c==null?s:Dt(c),h=u==null?s:Dt(u),s=new d(g,m+`leave`,c,n,i),s.target=f,s.relatedTarget=h,g=null,Tt(i)===r&&(d=new d(p,m+`enter`,u,n,i),d.target=h,d.relatedTarget=f,g=d),f=g,c&&u)b:{for(d=Dd,p=c,m=u,h=0,g=p;g;g=d(g))h++;g=0;for(var _=m;_;_=d(_))g++;for(;0<h-g;)p=d(p),h--;for(;0<g-h;)m=d(m),g--;for(;h--;){if(p===m||m!==null&&p===m.alternate){d=p;break b}p=d(p),m=d(m)}d=null}else d=null;c!==null&&Od(o,s,c,d,!1),u!==null&&f!==null&&Od(o,f,u,d,!0)}}a:{if(s=r?Dt(r):window,c=s.nodeName&&s.nodeName.toLowerCase(),c===`select`||c===`input`&&s.type===`file`)var v=yr;else if(pr(s)){if(br)v=kr;else{v=Dr;var y=Er}}else c=s.nodeName,!c||c.toLowerCase()!==`input`||s.type!==`checkbox`&&s.type!==`radio`?r&&an(r.elementType)&&(v=yr):v=Or;if(v&&=v(e,r)){mr(o,v,n,i);break a}y&&y(e,s,r),e===`focusout`&&r&&s.type===`number`&&r.memoizedProps.value!=null&&Xt(s,`number`,s.value)}switch(y=r?Dt(r):window,e){case`focusin`:(pr(y)||y.contentEditable===`true`)&&(zr=y,Br=r,Vr=null);break;case`focusout`:Vr=Br=zr=null;break;case`mousedown`:Hr=!0;break;case`contextmenu`:case`mouseup`:case`dragend`:Hr=!1,Ur(o,n,i);break;case`selectionchange`:if(Rr)break;case`keydown`:case`keyup`:Ur(o,n,i)}var b;if(tr)b:{switch(e){case`compositionstart`:var x=`onCompositionStart`;break b;case`compositionend`:x=`onCompositionEnd`;break b;case`compositionupdate`:x=`onCompositionUpdate`;break b}x=void 0}else lr?sr(e,n)&&(x=`onCompositionEnd`):e===`keydown`&&n.keyCode===229&&(x=`onCompositionStart`);x&&(ir&&n.locale!==`ko`&&(lr||x!==`onCompositionStart`?x===`onCompositionEnd`&&lr&&(b=wn()):(xn=i,Sn=`value`in xn?xn.value:xn.textContent,lr=!0)),y=Ed(r,x),0<y.length&&(x=new Hn(x,e,null,n,i),o.push({event:x,listeners:y}),b?x.data=b:(b=cr(n),b!==null&&(x.data=b)))),(b=rr?ur(e,n):dr(e,n))&&(x=Ed(r,`onBeforeInput`),0<x.length&&(y=new Hn(`onBeforeInput`,`beforeinput`,null,n,i),o.push({event:y,listeners:x}),y.data=b)),md(o,e,r,n,i)}yd(o,t)})}function Td(e,t,n){return{instance:e,listener:t,currentTarget:n}}function Ed(e,t){for(var n=t+`Capture`,r=[];e!==null;){var i=e,a=i.stateNode;if(i=i.tag,i!==5&&i!==26&&i!==27||a===null||(i=_n(e,n),i!=null&&r.unshift(Td(e,i,a)),i=_n(e,t),i!=null&&r.push(Td(e,i,a))),e.tag===3)return r;e=e.return}return[]}function Dd(e){if(e===null)return null;do e=e.return;while(e&&e.tag!==5&&e.tag!==27);return e||null}function Od(e,t,n,r,i){for(var a=t._reactName,o=[];n!==null&&n!==r;){var s=n,c=s.alternate,l=s.stateNode;if(s=s.tag,c!==null&&c===r)break;s!==5&&s!==26&&s!==27||l===null||(c=l,i?(l=_n(n,a),l!=null&&o.unshift(Td(n,l,c))):i||(l=_n(n,a),l!=null&&o.push(Td(n,l,c)))),n=n.return}o.length!==0&&e.push({event:t,listeners:o})}var kd=/\r\n?/g,Ad=/\u0000|\uFFFD/g;function jd(e){return(typeof e==`string`?e:``+e).replace(kd,`
`).replace(Ad,``)}function Md(e,t){return t=jd(t),jd(e)===t}function $(e,t,n,r,i,a){switch(n){case`children`:typeof r==`string`?t===`body`||t===`textarea`&&r===``||en(e,r):(typeof r==`number`||typeof r==`bigint`)&&t!==`body`&&en(e,``+r);break;case`className`:Rt(e,`class`,r);break;case`tabIndex`:Rt(e,`tabindex`,r);break;case`dir`:case`role`:case`viewBox`:case`width`:case`height`:Rt(e,n,r);break;case`style`:rn(e,r,a);break;case`data`:if(t!==`object`){Rt(e,`data`,r);break}case`src`:case`href`:if(r===``&&(t!==`a`||n!==`href`)){e.removeAttribute(n);break}if(r==null||typeof r==`function`||typeof r==`symbol`||typeof r==`boolean`){e.removeAttribute(n);break}r=cn(``+r),e.setAttribute(n,r);break;case`action`:case`formAction`:if(typeof r==`function`){e.setAttribute(n,`javascript:throw new Error('A React form was unexpectedly submitted. If you called form.submit() manually, consider using form.requestSubmit() instead. If you\\'re trying to use event.stopPropagation() in a submit event handler, consider also calling event.preventDefault().')`);break}if(typeof a==`function`&&(n===`formAction`?(t!==`input`&&$(e,t,`name`,i.name,i,null),$(e,t,`formEncType`,i.formEncType,i,null),$(e,t,`formMethod`,i.formMethod,i,null),$(e,t,`formTarget`,i.formTarget,i,null)):($(e,t,`encType`,i.encType,i,null),$(e,t,`method`,i.method,i,null),$(e,t,`target`,i.target,i,null))),r==null||typeof r==`symbol`||typeof r==`boolean`){e.removeAttribute(n);break}r=cn(``+r),e.setAttribute(n,r);break;case`onClick`:r!=null&&(e.onclick=ln);break;case`onScroll`:r!=null&&Q(`scroll`,e);break;case`onScrollEnd`:r!=null&&Q(`scrollend`,e);break;case`dangerouslySetInnerHTML`:if(r!=null){if(typeof r!=`object`||!(`__html`in r))throw Error(s(61));if(n=r.__html,n!=null){if(i.children!=null)throw Error(s(60));e.innerHTML=n}}break;case`multiple`:e.multiple=r&&typeof r!=`function`&&typeof r!=`symbol`;break;case`muted`:e.muted=r&&typeof r!=`function`&&typeof r!=`symbol`;break;case`suppressContentEditableWarning`:case`suppressHydrationWarning`:case`defaultValue`:case`defaultChecked`:case`innerHTML`:case`ref`:break;case`autoFocus`:break;case`xlinkHref`:if(r==null||typeof r==`function`||typeof r==`boolean`||typeof r==`symbol`){e.removeAttribute(`xlink:href`);break}n=cn(``+r),e.setAttributeNS(`http://www.w3.org/1999/xlink`,`xlink:href`,n);break;case`contentEditable`:case`spellCheck`:case`draggable`:case`value`:case`autoReverse`:case`externalResourcesRequired`:case`focusable`:case`preserveAlpha`:r!=null&&typeof r!=`function`&&typeof r!=`symbol`?e.setAttribute(n,``+r):e.removeAttribute(n);break;case`inert`:case`allowFullScreen`:case`async`:case`autoPlay`:case`controls`:case`default`:case`defer`:case`disabled`:case`disablePictureInPicture`:case`disableRemotePlayback`:case`formNoValidate`:case`hidden`:case`loop`:case`noModule`:case`noValidate`:case`open`:case`playsInline`:case`readOnly`:case`required`:case`reversed`:case`scoped`:case`seamless`:case`itemScope`:r&&typeof r!=`function`&&typeof r!=`symbol`?e.setAttribute(n,``):e.removeAttribute(n);break;case`capture`:case`download`:!0===r?e.setAttribute(n,``):!1!==r&&r!=null&&typeof r!=`function`&&typeof r!=`symbol`?e.setAttribute(n,r):e.removeAttribute(n);break;case`cols`:case`rows`:case`size`:case`span`:r!=null&&typeof r!=`function`&&typeof r!=`symbol`&&!isNaN(r)&&1<=r?e.setAttribute(n,r):e.removeAttribute(n);break;case`rowSpan`:case`start`:r==null||typeof r==`function`||typeof r==`symbol`||isNaN(r)?e.removeAttribute(n):e.setAttribute(n,r);break;case`popover`:Q(`beforetoggle`,e),Q(`toggle`,e),Lt(e,`popover`,r);break;case`xlinkActuate`:zt(e,`http://www.w3.org/1999/xlink`,`xlink:actuate`,r);break;case`xlinkArcrole`:zt(e,`http://www.w3.org/1999/xlink`,`xlink:arcrole`,r);break;case`xlinkRole`:zt(e,`http://www.w3.org/1999/xlink`,`xlink:role`,r);break;case`xlinkShow`:zt(e,`http://www.w3.org/1999/xlink`,`xlink:show`,r);break;case`xlinkTitle`:zt(e,`http://www.w3.org/1999/xlink`,`xlink:title`,r);break;case`xlinkType`:zt(e,`http://www.w3.org/1999/xlink`,`xlink:type`,r);break;case`xmlBase`:zt(e,`http://www.w3.org/XML/1998/namespace`,`xml:base`,r);break;case`xmlLang`:zt(e,`http://www.w3.org/XML/1998/namespace`,`xml:lang`,r);break;case`xmlSpace`:zt(e,`http://www.w3.org/XML/1998/namespace`,`xml:space`,r);break;case`is`:Lt(e,`is`,r);break;case`innerText`:case`textContent`:break;default:(!(2<n.length)||n[0]!==`o`&&n[0]!==`O`||n[1]!==`n`&&n[1]!==`N`)&&(n=on.get(n)||n,Lt(e,n,r))}}function Nd(e,t,n,r,i,a){switch(n){case`style`:rn(e,r,a);break;case`dangerouslySetInnerHTML`:if(r!=null){if(typeof r!=`object`||!(`__html`in r))throw Error(s(61));if(n=r.__html,n!=null){if(i.children!=null)throw Error(s(60));e.innerHTML=n}}break;case`children`:typeof r==`string`?en(e,r):(typeof r==`number`||typeof r==`bigint`)&&en(e,``+r);break;case`onScroll`:r!=null&&Q(`scroll`,e);break;case`onScrollEnd`:r!=null&&Q(`scrollend`,e);break;case`onClick`:r!=null&&(e.onclick=ln);break;case`suppressContentEditableWarning`:case`suppressHydrationWarning`:case`innerHTML`:case`ref`:break;case`innerText`:case`textContent`:break;default:if(!At.hasOwnProperty(n))a:{if(n[0]===`o`&&n[1]===`n`&&(i=n.endsWith(`Capture`),t=n.slice(2,i?n.length-7:void 0),a=e[_t]||null,a=a==null?null:a[n],typeof a==`function`&&e.removeEventListener(t,a,i),typeof r==`function`)){typeof a!=`function`&&a!==null&&(n in e?e[n]=null:e.hasAttribute(n)&&e.removeAttribute(n)),e.addEventListener(t,r,i);break a}n in e?e[n]=r:!0===r?e.setAttribute(n,``):Lt(e,n,r)}}}function Pd(e,t,n){switch(t){case`div`:case`span`:case`svg`:case`path`:case`a`:case`g`:case`p`:case`li`:break;case`img`:Q(`error`,e),Q(`load`,e);var r=!1,i=!1,a;for(a in n)if(n.hasOwnProperty(a)){var o=n[a];if(o!=null)switch(a){case`src`:r=!0;break;case`srcSet`:i=!0;break;case`children`:case`dangerouslySetInnerHTML`:throw Error(s(137,t));default:$(e,t,a,o,n,null)}}i&&$(e,t,`srcSet`,n.srcSet,n,null),r&&$(e,t,`src`,n.src,n,null);return;case`input`:Q(`invalid`,e);var c=a=o=i=null,l=null,u=null;for(r in n)if(n.hasOwnProperty(r)){var d=n[r];if(d!=null)switch(r){case`name`:i=d;break;case`type`:o=d;break;case`checked`:l=d;break;case`defaultChecked`:u=d;break;case`value`:a=d;break;case`defaultValue`:c=d;break;case`children`:case`dangerouslySetInnerHTML`:if(d!=null)throw Error(s(137,t));break;default:$(e,t,r,d,n,null)}}Yt(e,a,c,l,u,o,i,!1);return;case`select`:for(i in Q(`invalid`,e),r=o=a=null,n)if(n.hasOwnProperty(i)&&(c=n[i],c!=null))switch(i){case`value`:a=c;break;case`defaultValue`:o=c;break;case`multiple`:r=c;default:$(e,t,i,c,n,null)}t=a,n=o,e.multiple=!!r,t==null?n!=null&&Zt(e,!!r,n,!0):Zt(e,!!r,t,!1);return;case`textarea`:for(o in Q(`invalid`,e),a=i=r=null,n)if(n.hasOwnProperty(o)&&(c=n[o],c!=null))switch(o){case`value`:r=c;break;case`defaultValue`:i=c;break;case`children`:a=c;break;case`dangerouslySetInnerHTML`:if(c!=null)throw Error(s(91));break;default:$(e,t,o,c,n,null)}$t(e,r,i,a);return;case`option`:for(l in n)if(n.hasOwnProperty(l)&&(r=n[l],r!=null))switch(l){case`selected`:e.selected=r&&typeof r!=`function`&&typeof r!=`symbol`;break;default:$(e,t,l,r,n,null)}return;case`dialog`:Q(`beforetoggle`,e),Q(`toggle`,e),Q(`cancel`,e),Q(`close`,e);break;case`iframe`:case`object`:Q(`load`,e);break;case`video`:case`audio`:for(r=0;r<_d.length;r++)Q(_d[r],e);break;case`image`:Q(`error`,e),Q(`load`,e);break;case`details`:Q(`toggle`,e);break;case`embed`:case`source`:case`link`:Q(`error`,e),Q(`load`,e);case`area`:case`base`:case`br`:case`col`:case`hr`:case`keygen`:case`meta`:case`param`:case`track`:case`wbr`:case`menuitem`:for(u in n)if(n.hasOwnProperty(u)&&(r=n[u],r!=null))switch(u){case`children`:case`dangerouslySetInnerHTML`:throw Error(s(137,t));default:$(e,t,u,r,n,null)}return;default:if(an(t)){for(d in n)n.hasOwnProperty(d)&&(r=n[d],r!==void 0&&Nd(e,t,d,r,n,void 0));return}}for(c in n)n.hasOwnProperty(c)&&(r=n[c],r!=null&&$(e,t,c,r,n,null))}function Fd(e,t,n,r){switch(t){case`div`:case`span`:case`svg`:case`path`:case`a`:case`g`:case`p`:case`li`:break;case`input`:var i=null,a=null,o=null,c=null,l=null,u=null,d=null;for(m in n){var f=n[m];if(n.hasOwnProperty(m)&&f!=null)switch(m){case`checked`:break;case`value`:break;case`defaultValue`:l=f;default:r.hasOwnProperty(m)||$(e,t,m,null,r,f)}}for(var p in r){var m=r[p];if(f=n[p],r.hasOwnProperty(p)&&(m!=null||f!=null))switch(p){case`type`:a=m;break;case`name`:i=m;break;case`checked`:u=m;break;case`defaultChecked`:d=m;break;case`value`:o=m;break;case`defaultValue`:c=m;break;case`children`:case`dangerouslySetInnerHTML`:if(m!=null)throw Error(s(137,t));break;default:m!==f&&$(e,t,p,m,r,f)}}Jt(e,o,c,l,u,d,a,i);return;case`select`:for(a in m=o=c=p=null,n)if(l=n[a],n.hasOwnProperty(a)&&l!=null)switch(a){case`value`:break;case`multiple`:m=l;default:r.hasOwnProperty(a)||$(e,t,a,null,r,l)}for(i in r)if(a=r[i],l=n[i],r.hasOwnProperty(i)&&(a!=null||l!=null))switch(i){case`value`:p=a;break;case`defaultValue`:c=a;break;case`multiple`:o=a;default:a!==l&&$(e,t,i,a,r,l)}t=c,n=o,r=m,p==null?!!r!=!!n&&(t==null?Zt(e,!!n,n?[]:``,!1):Zt(e,!!n,t,!0)):Zt(e,!!n,p,!1);return;case`textarea`:for(c in m=p=null,n)if(i=n[c],n.hasOwnProperty(c)&&i!=null&&!r.hasOwnProperty(c))switch(c){case`value`:break;case`children`:break;default:$(e,t,c,null,r,i)}for(o in r)if(i=r[o],a=n[o],r.hasOwnProperty(o)&&(i!=null||a!=null))switch(o){case`value`:p=i;break;case`defaultValue`:m=i;break;case`children`:break;case`dangerouslySetInnerHTML`:if(i!=null)throw Error(s(91));break;default:i!==a&&$(e,t,o,i,r,a)}Qt(e,p,m);return;case`option`:for(var h in n)if(p=n[h],n.hasOwnProperty(h)&&p!=null&&!r.hasOwnProperty(h))switch(h){case`selected`:e.selected=!1;break;default:$(e,t,h,null,r,p)}for(l in r)if(p=r[l],m=n[l],r.hasOwnProperty(l)&&p!==m&&(p!=null||m!=null))switch(l){case`selected`:e.selected=p&&typeof p!=`function`&&typeof p!=`symbol`;break;default:$(e,t,l,p,r,m)}return;case`img`:case`link`:case`area`:case`base`:case`br`:case`col`:case`embed`:case`hr`:case`keygen`:case`meta`:case`param`:case`source`:case`track`:case`wbr`:case`menuitem`:for(var g in n)p=n[g],n.hasOwnProperty(g)&&p!=null&&!r.hasOwnProperty(g)&&$(e,t,g,null,r,p);for(u in r)if(p=r[u],m=n[u],r.hasOwnProperty(u)&&p!==m&&(p!=null||m!=null))switch(u){case`children`:case`dangerouslySetInnerHTML`:if(p!=null)throw Error(s(137,t));break;default:$(e,t,u,p,r,m)}return;default:if(an(t)){for(var _ in n)p=n[_],n.hasOwnProperty(_)&&p!==void 0&&!r.hasOwnProperty(_)&&Nd(e,t,_,void 0,r,p);for(d in r)p=r[d],m=n[d],!r.hasOwnProperty(d)||p===m||p===void 0&&m===void 0||Nd(e,t,d,p,r,m);return}}for(var v in n)p=n[v],n.hasOwnProperty(v)&&p!=null&&!r.hasOwnProperty(v)&&$(e,t,v,null,r,p);for(f in r)p=r[f],m=n[f],!r.hasOwnProperty(f)||p===m||p==null&&m==null||$(e,t,f,p,r,m)}function Id(e){switch(e){case`css`:case`script`:case`font`:case`img`:case`image`:case`input`:case`link`:return!0;default:return!1}}function Ld(){if(typeof performance.getEntriesByType==`function`){for(var e=0,t=0,n=performance.getEntriesByType(`resource`),r=0;r<n.length;r++){var i=n[r],a=i.transferSize,o=i.initiatorType,s=i.duration;if(a&&s&&Id(o)){for(o=0,s=i.responseEnd,r+=1;r<n.length;r++){var c=n[r],l=c.startTime;if(l>s)break;var u=c.transferSize,d=c.initiatorType;u&&Id(d)&&(c=c.responseEnd,o+=u*(c<s?1:(s-l)/(c-l)))}if(--r,t+=8*(a+o)/(i.duration/1e3),e++,10<e)break}}if(0<e)return t/e/1e6}return navigator.connection&&(e=navigator.connection.downlink,typeof e==`number`)?e:5}var Rd=null,zd=null;function Bd(e){return e.nodeType===9?e:e.ownerDocument}function Vd(e){switch(e){case`http://www.w3.org/2000/svg`:return 1;case`http://www.w3.org/1998/Math/MathML`:return 2;default:return 0}}function Hd(e,t){if(e===0)switch(t){case`svg`:return 1;case`math`:return 2;default:return 0}return e===1&&t===`foreignObject`?0:e}function Ud(e,t){return e===`textarea`||e===`noscript`||typeof t.children==`string`||typeof t.children==`number`||typeof t.children==`bigint`||typeof t.dangerouslySetInnerHTML==`object`&&t.dangerouslySetInnerHTML!==null&&t.dangerouslySetInnerHTML.__html!=null}var Wd=null;function Gd(){var e=window.event;return e&&e.type===`popstate`?e!==Wd&&(Wd=e,!0):(Wd=null,!1)}var Kd=typeof setTimeout==`function`?setTimeout:void 0,qd=typeof clearTimeout==`function`?clearTimeout:void 0,Jd=typeof Promise==`function`?Promise:void 0,Yd=typeof queueMicrotask==`function`?queueMicrotask:Jd===void 0?Kd:function(e){return Jd.resolve(null).then(e).catch(Xd)};function Xd(e){setTimeout(function(){throw e})}function Zd(e){return e===`head`}function Qd(e,t){var n=t,r=0;do{var i=n.nextSibling;if(e.removeChild(n),i&&i.nodeType===8){if(n=i.data,n===`/$`||n===`/&`){if(r===0){e.removeChild(i),Np(t);return}r--}else if(n===`$`||n===`$?`||n===`$~`||n===`$!`||n===`&`)r++;else if(n===`html`)pf(e.ownerDocument.documentElement);else if(n===`head`){n=e.ownerDocument.head,pf(n);for(var a=n.firstChild;a;){var o=a.nextSibling,s=a.nodeName;a[Ct]||s===`SCRIPT`||s===`STYLE`||s===`LINK`&&a.rel.toLowerCase()===`stylesheet`||n.removeChild(a),a=o}}else n===`body`&&pf(e.ownerDocument.body)}n=i}while(n);Np(t)}function $d(e,t){var n=e;e=0;do{var r=n.nextSibling;if(n.nodeType===1?t?(n._stashedDisplay=n.style.display,n.style.display=`none`):(n.style.display=n._stashedDisplay||``,n.getAttribute(`style`)===``&&n.removeAttribute(`style`)):n.nodeType===3&&(t?(n._stashedText=n.nodeValue,n.nodeValue=``):n.nodeValue=n._stashedText||``),r&&r.nodeType===8){if(n=r.data,n===`/$`){if(e===0)break;e--}else n!==`$`&&n!==`$?`&&n!==`$~`&&n!==`$!`||e++}n=r}while(n)}function ef(e){var t=e.firstChild;for(t&&t.nodeType===10&&(t=t.nextSibling);t;){var n=t;switch(t=t.nextSibling,n.nodeName){case`HTML`:case`HEAD`:case`BODY`:ef(n),wt(n);continue;case`SCRIPT`:case`STYLE`:continue;case`LINK`:if(n.rel.toLowerCase()===`stylesheet`)continue}e.removeChild(n)}}function tf(e,t,n,r){for(;e.nodeType===1;){var i=n;if(e.nodeName.toLowerCase()!==t.toLowerCase()){if(!r&&(e.nodeName!==`INPUT`||e.type!==`hidden`))break}else if(!r){if(t===`input`&&e.type===`hidden`){var a=i.name==null?null:``+i.name;if(i.type===`hidden`&&e.getAttribute(`name`)===a)return e}else return e}else if(!e[Ct])switch(t){case`meta`:if(!e.hasAttribute(`itemprop`))break;return e;case`link`:if(a=e.getAttribute(`rel`),a===`stylesheet`&&e.hasAttribute(`data-precedence`)||a!==i.rel||e.getAttribute(`href`)!==(i.href==null||i.href===``?null:i.href)||e.getAttribute(`crossorigin`)!==(i.crossOrigin==null?null:i.crossOrigin)||e.getAttribute(`title`)!==(i.title==null?null:i.title))break;return e;case`style`:if(e.hasAttribute(`data-precedence`))break;return e;case`script`:if(a=e.getAttribute(`src`),(a!==(i.src==null?null:i.src)||e.getAttribute(`type`)!==(i.type==null?null:i.type)||e.getAttribute(`crossorigin`)!==(i.crossOrigin==null?null:i.crossOrigin))&&a&&e.hasAttribute(`async`)&&!e.hasAttribute(`itemprop`))break;return e;default:return e}if(e=cf(e.nextSibling),e===null)break}return null}function nf(e,t,n){if(t===``)return null;for(;e.nodeType!==3;)if((e.nodeType!==1||e.nodeName!==`INPUT`||e.type!==`hidden`)&&!n||(e=cf(e.nextSibling),e===null))return null;return e}function rf(e,t){for(;e.nodeType!==8;)if((e.nodeType!==1||e.nodeName!==`INPUT`||e.type!==`hidden`)&&!t||(e=cf(e.nextSibling),e===null))return null;return e}function af(e){return e.data===`$?`||e.data===`$~`}function of(e){return e.data===`$!`||e.data===`$?`&&e.ownerDocument.readyState!==`loading`}function sf(e,t){var n=e.ownerDocument;if(e.data===`$~`)e._reactRetry=t;else if(e.data!==`$?`||n.readyState!==`loading`)t();else{var r=function(){t(),n.removeEventListener(`DOMContentLoaded`,r)};n.addEventListener(`DOMContentLoaded`,r),e._reactRetry=r}}function cf(e){for(;e!=null;e=e.nextSibling){var t=e.nodeType;if(t===1||t===3)break;if(t===8){if(t=e.data,t===`$`||t===`$!`||t===`$?`||t===`$~`||t===`&`||t===`F!`||t===`F`)break;if(t===`/$`||t===`/&`)return null}}return e}var lf=null;function uf(e){e=e.nextSibling;for(var t=0;e;){if(e.nodeType===8){var n=e.data;if(n===`/$`||n===`/&`){if(t===0)return cf(e.nextSibling);t--}else n!==`$`&&n!==`$!`&&n!==`$?`&&n!==`$~`&&n!==`&`||t++}e=e.nextSibling}return null}function df(e){e=e.previousSibling;for(var t=0;e;){if(e.nodeType===8){var n=e.data;if(n===`$`||n===`$!`||n===`$?`||n===`$~`||n===`&`){if(t===0)return e;t--}else n!==`/$`&&n!==`/&`||t++}e=e.previousSibling}return null}function ff(e,t,n){switch(t=Bd(n),e){case`html`:if(e=t.documentElement,!e)throw Error(s(452));return e;case`head`:if(e=t.head,!e)throw Error(s(453));return e;case`body`:if(e=t.body,!e)throw Error(s(454));return e;default:throw Error(s(451))}}function pf(e){for(var t=e.attributes;t.length;)e.removeAttributeNode(t[0]);wt(e)}var mf=new Map,hf=new Set;function gf(e){return typeof e.getRootNode==`function`?e.getRootNode():e.nodeType===9?e:e.ownerDocument}var _f=D.d;D.d={f:vf,r:yf,D:Sf,C:Cf,L:wf,m:Tf,X:Df,S:Ef,M:Of};function vf(){var e=_f.f(),t=bu();return e||t}function yf(e){var t=Et(e);t!==null&&t.tag===5&&t.type===`form`?js(t):_f.r(e)}var bf=typeof document>`u`?null:document;function xf(e,t,n){var r=bf;if(r&&typeof t==`string`&&t){var i=qt(t);i=`link[rel="`+e+`"][href="`+i+`"]`,typeof n==`string`&&(i+=`[crossorigin="`+n+`"]`),hf.has(i)||(hf.add(i),e={rel:e,crossOrigin:n,href:t},r.querySelector(i)===null&&(t=r.createElement(`link`),Pd(t,`link`,e),k(t),r.head.appendChild(t)))}}function Sf(e){_f.D(e),xf(`dns-prefetch`,e,null)}function Cf(e,t){_f.C(e,t),xf(`preconnect`,e,t)}function wf(e,t,n){_f.L(e,t,n);var r=bf;if(r&&e&&t){var i=`link[rel="preload"][as="`+qt(t)+`"]`;t===`image`&&n&&n.imageSrcSet?(i+=`[imagesrcset="`+qt(n.imageSrcSet)+`"]`,typeof n.imageSizes==`string`&&(i+=`[imagesizes="`+qt(n.imageSizes)+`"]`)):i+=`[href="`+qt(e)+`"]`;var a=i;switch(t){case`style`:a=Af(e);break;case`script`:a=Pf(e)}mf.has(a)||(e=h({rel:`preload`,href:t===`image`&&n&&n.imageSrcSet?void 0:e,as:t},n),mf.set(a,e),r.querySelector(i)!==null||t===`style`&&r.querySelector(jf(a))||t===`script`&&r.querySelector(Ff(a))||(t=r.createElement(`link`),Pd(t,`link`,e),k(t),r.head.appendChild(t)))}}function Tf(e,t){_f.m(e,t);var n=bf;if(n&&e){var r=t&&typeof t.as==`string`?t.as:`script`,i=`link[rel="modulepreload"][as="`+qt(r)+`"][href="`+qt(e)+`"]`,a=i;switch(r){case`audioworklet`:case`paintworklet`:case`serviceworker`:case`sharedworker`:case`worker`:case`script`:a=Pf(e)}if(!mf.has(a)&&(e=h({rel:`modulepreload`,href:e},t),mf.set(a,e),n.querySelector(i)===null)){switch(r){case`audioworklet`:case`paintworklet`:case`serviceworker`:case`sharedworker`:case`worker`:case`script`:if(n.querySelector(Ff(a)))return}r=n.createElement(`link`),Pd(r,`link`,e),k(r),n.head.appendChild(r)}}}function Ef(e,t,n){_f.S(e,t,n);var r=bf;if(r&&e){var i=Ot(r).hoistableStyles,a=Af(e);t||=`default`;var o=i.get(a);if(!o){var s={loading:0,preload:null};if(o=r.querySelector(jf(a)))s.loading=5;else{e=h({rel:`stylesheet`,href:e,"data-precedence":t},n),(n=mf.get(a))&&Rf(e,n);var c=o=r.createElement(`link`);k(c),Pd(c,`link`,e),c._p=new Promise(function(e,t){c.onload=e,c.onerror=t}),c.addEventListener(`load`,function(){s.loading|=1}),c.addEventListener(`error`,function(){s.loading|=2}),s.loading|=4,Lf(o,t,r)}o={type:`stylesheet`,instance:o,count:1,state:s},i.set(a,o)}}}function Df(e,t){_f.X(e,t);var n=bf;if(n&&e){var r=Ot(n).hoistableScripts,i=Pf(e),a=r.get(i);a||(a=n.querySelector(Ff(i)),a||(e=h({src:e,async:!0},t),(t=mf.get(i))&&zf(e,t),a=n.createElement(`script`),k(a),Pd(a,`link`,e),n.head.appendChild(a)),a={type:`script`,instance:a,count:1,state:null},r.set(i,a))}}function Of(e,t){_f.M(e,t);var n=bf;if(n&&e){var r=Ot(n).hoistableScripts,i=Pf(e),a=r.get(i);a||(a=n.querySelector(Ff(i)),a||(e=h({src:e,async:!0,type:`module`},t),(t=mf.get(i))&&zf(e,t),a=n.createElement(`script`),k(a),Pd(a,`link`,e),n.head.appendChild(a)),a={type:`script`,instance:a,count:1,state:null},r.set(i,a))}}function kf(e,t,n,r){var i=(i=_e.current)?gf(i):null;if(!i)throw Error(s(446));switch(e){case`meta`:case`title`:return null;case`style`:return typeof n.precedence==`string`&&typeof n.href==`string`?(t=Af(n.href),n=Ot(i).hoistableStyles,r=n.get(t),r||(r={type:`style`,instance:null,count:0,state:null},n.set(t,r)),r):{type:`void`,instance:null,count:0,state:null};case`link`:if(n.rel===`stylesheet`&&typeof n.href==`string`&&typeof n.precedence==`string`){e=Af(n.href);var a=Ot(i).hoistableStyles,o=a.get(e);if(o||(i=i.ownerDocument||i,o={type:`stylesheet`,instance:null,count:0,state:{loading:0,preload:null}},a.set(e,o),(a=i.querySelector(jf(e)))&&!a._p&&(o.instance=a,o.state.loading=5),mf.has(e)||(n={rel:`preload`,as:`style`,href:n.href,crossOrigin:n.crossOrigin,integrity:n.integrity,media:n.media,hrefLang:n.hrefLang,referrerPolicy:n.referrerPolicy},mf.set(e,n),a||Nf(i,e,n,o.state))),t&&r===null)throw Error(s(528,``));return o}if(t&&r!==null)throw Error(s(529,``));return null;case`script`:return t=n.async,n=n.src,typeof n==`string`&&t&&typeof t!=`function`&&typeof t!=`symbol`?(t=Pf(n),n=Ot(i).hoistableScripts,r=n.get(t),r||(r={type:`script`,instance:null,count:0,state:null},n.set(t,r)),r):{type:`void`,instance:null,count:0,state:null};default:throw Error(s(444,e))}}function Af(e){return`href="`+qt(e)+`"`}function jf(e){return`link[rel="stylesheet"][`+e+`]`}function Mf(e){return h({},e,{"data-precedence":e.precedence,precedence:null})}function Nf(e,t,n,r){e.querySelector(`link[rel="preload"][as="style"][`+t+`]`)?r.loading=1:(t=e.createElement(`link`),r.preload=t,t.addEventListener(`load`,function(){return r.loading|=1}),t.addEventListener(`error`,function(){return r.loading|=2}),Pd(t,`link`,n),k(t),e.head.appendChild(t))}function Pf(e){return`[src="`+qt(e)+`"]`}function Ff(e){return`script[async]`+e}function If(e,t,n){if(t.count++,t.instance===null)switch(t.type){case`style`:var r=e.querySelector(`style[data-href~="`+qt(n.href)+`"]`);if(r)return t.instance=r,k(r),r;var i=h({},n,{"data-href":n.href,"data-precedence":n.precedence,href:null,precedence:null});return r=(e.ownerDocument||e).createElement(`style`),k(r),Pd(r,`style`,i),Lf(r,n.precedence,e),t.instance=r;case`stylesheet`:i=Af(n.href);var a=e.querySelector(jf(i));if(a)return t.state.loading|=4,t.instance=a,k(a),a;r=Mf(n),(i=mf.get(i))&&Rf(r,i),a=(e.ownerDocument||e).createElement(`link`),k(a);var o=a;return o._p=new Promise(function(e,t){o.onload=e,o.onerror=t}),Pd(a,`link`,r),t.state.loading|=4,Lf(a,n.precedence,e),t.instance=a;case`script`:return a=Pf(n.src),(i=e.querySelector(Ff(a)))?(t.instance=i,k(i),i):(r=n,(i=mf.get(a))&&(r=h({},n),zf(r,i)),e=e.ownerDocument||e,i=e.createElement(`script`),k(i),Pd(i,`link`,r),e.head.appendChild(i),t.instance=i);case`void`:return null;default:throw Error(s(443,t.type))}else t.type===`stylesheet`&&!(t.state.loading&4)&&(r=t.instance,t.state.loading|=4,Lf(r,n.precedence,e));return t.instance}function Lf(e,t,n){for(var r=n.querySelectorAll(`link[rel="stylesheet"][data-precedence],style[data-precedence]`),i=r.length?r[r.length-1]:null,a=i,o=0;o<r.length;o++){var s=r[o];if(s.dataset.precedence===t)a=s;else if(a!==i)break}a?a.parentNode.insertBefore(e,a.nextSibling):(t=n.nodeType===9?n.head:n,t.insertBefore(e,t.firstChild))}function Rf(e,t){e.crossOrigin??=t.crossOrigin,e.referrerPolicy??=t.referrerPolicy,e.title??=t.title}function zf(e,t){e.crossOrigin??=t.crossOrigin,e.referrerPolicy??=t.referrerPolicy,e.integrity??=t.integrity}var Bf=null;function Vf(e,t,n){if(Bf===null){var r=new Map,i=Bf=new Map;i.set(n,r)}else i=Bf,r=i.get(n),r||(r=new Map,i.set(n,r));if(r.has(e))return r;for(r.set(e,null),n=n.getElementsByTagName(e),i=0;i<n.length;i++){var a=n[i];if(!(a[Ct]||a[gt]||e===`link`&&a.getAttribute(`rel`)===`stylesheet`)&&a.namespaceURI!==`http://www.w3.org/2000/svg`){var o=a.getAttribute(t)||``;o=e+o;var s=r.get(o);s?s.push(a):r.set(o,[a])}}return r}function Hf(e,t,n){e=e.ownerDocument||e,e.head.insertBefore(n,t===`title`?e.querySelector(`head > title`):null)}function Uf(e,t,n){if(n===1||t.itemProp!=null)return!1;switch(e){case`meta`:case`title`:return!0;case`style`:if(typeof t.precedence!=`string`||typeof t.href!=`string`||t.href===``)break;return!0;case`link`:if(typeof t.rel!=`string`||typeof t.href!=`string`||t.href===``||t.onLoad||t.onError)break;switch(t.rel){case`stylesheet`:return e=t.disabled,typeof t.precedence==`string`&&e==null;default:return!0}case`script`:if(t.async&&typeof t.async!=`function`&&typeof t.async!=`symbol`&&!t.onLoad&&!t.onError&&t.src&&typeof t.src==`string`)return!0}return!1}function Wf(e){return!(e.type===`stylesheet`&&!(e.state.loading&3))}function Gf(e,t,n,r){if(n.type===`stylesheet`&&(typeof r.media!=`string`||!1!==matchMedia(r.media).matches)&&!(n.state.loading&4)){if(n.instance===null){var i=Af(r.href),a=t.querySelector(jf(i));if(a){t=a._p,typeof t==`object`&&t&&typeof t.then==`function`&&(e.count++,e=Jf.bind(e),t.then(e,e)),n.state.loading|=4,n.instance=a,k(a);return}a=t.ownerDocument||t,r=Mf(r),(i=mf.get(i))&&Rf(r,i),a=a.createElement(`link`),k(a);var o=a;o._p=new Promise(function(e,t){o.onload=e,o.onerror=t}),Pd(a,`link`,r),n.instance=a}e.stylesheets===null&&(e.stylesheets=new Map),e.stylesheets.set(n,t),(t=n.state.preload)&&!(n.state.loading&3)&&(e.count++,n=Jf.bind(e),t.addEventListener(`load`,n),t.addEventListener(`error`,n))}}var Kf=0;function qf(e,t){return e.stylesheets&&e.count===0&&Xf(e,e.stylesheets),0<e.count||0<e.imgCount?function(n){var r=setTimeout(function(){if(e.stylesheets&&Xf(e,e.stylesheets),e.unsuspend){var t=e.unsuspend;e.unsuspend=null,t()}},6e4+t);0<e.imgBytes&&Kf===0&&(Kf=62500*Ld());var i=setTimeout(function(){if(e.waitingForImages=!1,e.count===0&&(e.stylesheets&&Xf(e,e.stylesheets),e.unsuspend)){var t=e.unsuspend;e.unsuspend=null,t()}},(e.imgBytes>Kf?50:800)+t);return e.unsuspend=n,function(){e.unsuspend=null,clearTimeout(r),clearTimeout(i)}}:null}function Jf(){if(this.count--,this.count===0&&(this.imgCount===0||!this.waitingForImages)){if(this.stylesheets)Xf(this,this.stylesheets);else if(this.unsuspend){var e=this.unsuspend;this.unsuspend=null,e()}}}var Yf=null;function Xf(e,t){e.stylesheets=null,e.unsuspend!==null&&(e.count++,Yf=new Map,t.forEach(Zf,e),Yf=null,Jf.call(e))}function Zf(e,t){if(!(t.state.loading&4)){var n=Yf.get(e);if(n)var r=n.get(null);else{n=new Map,Yf.set(e,n);for(var i=e.querySelectorAll(`link[data-precedence],style[data-precedence]`),a=0;a<i.length;a++){var o=i[a];(o.nodeName===`LINK`||o.getAttribute(`media`)!==`not all`)&&(n.set(o.dataset.precedence,o),r=o)}r&&n.set(null,r)}i=t.instance,o=i.getAttribute(`data-precedence`),a=n.get(o)||r,a===r&&n.set(null,i),n.set(o,i),this.count++,r=Jf.bind(this),i.addEventListener(`load`,r),i.addEventListener(`error`,r),a?a.parentNode.insertBefore(i,a.nextSibling):(e=e.nodeType===9?e.head:e,e.insertBefore(i,e.firstChild)),t.state.loading|=4}}var Qf={$$typeof:C,Provider:null,Consumer:null,_currentValue:ue,_currentValue2:ue,_threadCount:0};function $f(e,t,n,r,i,a,o,s,c){this.tag=1,this.containerInfo=e,this.pingCache=this.current=this.pendingChildren=null,this.timeoutHandle=-1,this.callbackNode=this.next=this.pendingContext=this.context=this.cancelPendingCommit=null,this.callbackPriority=0,this.expirationTimes=at(-1),this.entangledLanes=this.shellSuspendCounter=this.errorRecoveryDisabledLanes=this.expiredLanes=this.warmLanes=this.pingedLanes=this.suspendedLanes=this.pendingLanes=0,this.entanglements=at(0),this.hiddenUpdates=at(null),this.identifierPrefix=r,this.onUncaughtError=i,this.onCaughtError=a,this.onRecoverableError=o,this.pooledCache=null,this.pooledCacheLanes=0,this.formState=c,this.incompleteTransitions=new Map}function ep(e,t,n,r,i,a,o,s,c,l,u,d){return e=new $f(e,t,n,o,c,l,u,d,s),t=1,!0===a&&(t|=24),a=_i(3,null,null,t),e.current=a,a.stateNode=e,t=ha(),t.refCount++,e.pooledCache=t,t.refCount++,a.memoizedState={element:r,isDehydrated:n,cache:t},Ja(a),e}function tp(e){return e?(e=hi,e):hi}function np(e,t,n,r,i,a){i=tp(i),r.context===null?r.context=i:r.pendingContext=i,r=Xa(t),r.payload={element:n},a=a===void 0?null:a,a!==null&&(r.callback=a),n=Za(e,r,t),n!==null&&(hu(n,e,t),Qa(n,e,t))}function rp(e,t){if(e=e.memoizedState,e!==null&&e.dehydrated!==null){var n=e.retryLane;e.retryLane=n!==0&&n<t?n:t}}function ip(e,t){rp(e,t),(e=e.alternate)&&rp(e,t)}function ap(e){if(e.tag===13||e.tag===31){var t=fi(e,67108864);t!==null&&hu(t,e,67108864),ip(e,67108864)}}function op(e){if(e.tag===13||e.tag===31){var t=pu();t=dt(t);var n=fi(e,t);n!==null&&hu(n,e,t),ip(e,t)}}var sp=!0;function cp(e,t,n,r){var i=E.T;E.T=null;var a=D.p;try{D.p=2,up(e,t,n,r)}finally{D.p=a,E.T=i}}function lp(e,t,n,r){var i=E.T;E.T=null;var a=D.p;try{D.p=8,up(e,t,n,r)}finally{D.p=a,E.T=i}}function up(e,t,n,r){if(sp){var i=dp(r);if(i===null)wd(e,t,r,fp,n),Cp(e,r);else if(Tp(i,e,t,n,r))r.stopPropagation();else if(Cp(e,r),t&4&&-1<Sp.indexOf(e)){for(;i!==null;){var a=Et(i);if(a!==null)switch(a.tag){case 3:if(a=a.stateNode,a.current.memoizedState.isDehydrated){var o=et(a.pendingLanes);if(o!==0){var s=a;for(s.pendingLanes|=2,s.entangledLanes|=2;o;){var c=1<<31-qe(o);s.entanglements[1]|=c,o&=~c}rd(a),!(W&6)&&(nu=Fe()+500,id(0,!1))}}break;case 31:case 13:s=fi(a,2),s!==null&&hu(s,a,2),bu(),ip(a,2)}if(a=dp(r),a===null&&wd(e,t,r,fp,n),a===i)break;i=a}i!==null&&r.stopPropagation()}else wd(e,t,r,null,n)}}function dp(e){return e=dn(e),pp(e)}var fp=null;function pp(e){if(fp=null,e=Tt(e),e!==null){var t=l(e);if(t===null)e=null;else{var n=t.tag;if(n===13){if(e=u(t),e!==null)return e;e=null}else if(n===31){if(e=d(t),e!==null)return e;e=null}else if(n===3){if(t.stateNode.current.memoizedState.isDehydrated)return t.tag===3?t.stateNode.containerInfo:null;e=null}else t!==e&&(e=null)}}return fp=e,null}function mp(e){switch(e){case`beforetoggle`:case`cancel`:case`click`:case`close`:case`contextmenu`:case`copy`:case`cut`:case`auxclick`:case`dblclick`:case`dragend`:case`dragstart`:case`drop`:case`focusin`:case`focusout`:case`input`:case`invalid`:case`keydown`:case`keypress`:case`keyup`:case`mousedown`:case`mouseup`:case`paste`:case`pause`:case`play`:case`pointercancel`:case`pointerdown`:case`pointerup`:case`ratechange`:case`reset`:case`resize`:case`seeked`:case`submit`:case`toggle`:case`touchcancel`:case`touchend`:case`touchstart`:case`volumechange`:case`change`:case`selectionchange`:case`textInput`:case`compositionstart`:case`compositionend`:case`compositionupdate`:case`beforeblur`:case`afterblur`:case`beforeinput`:case`blur`:case`fullscreenchange`:case`focus`:case`hashchange`:case`popstate`:case`select`:case`selectstart`:return 2;case`drag`:case`dragenter`:case`dragexit`:case`dragleave`:case`dragover`:case`mousemove`:case`mouseout`:case`mouseover`:case`pointermove`:case`pointerout`:case`pointerover`:case`scroll`:case`touchmove`:case`wheel`:case`mouseenter`:case`mouseleave`:case`pointerenter`:case`pointerleave`:return 8;case`message`:switch(Ie()){case Le:return 2;case Re:return 8;case ze:case Be:return 32;case Ve:return 268435456;default:return 32}default:return 32}}var hp=!1,gp=null,_p=null,vp=null,yp=new Map,bp=new Map,xp=[],Sp=`mousedown mouseup touchcancel touchend touchstart auxclick dblclick pointercancel pointerdown pointerup dragend dragstart drop compositionend compositionstart keydown keypress keyup input textInput copy cut paste click change contextmenu reset`.split(` `);function Cp(e,t){switch(e){case`focusin`:case`focusout`:gp=null;break;case`dragenter`:case`dragleave`:_p=null;break;case`mouseover`:case`mouseout`:vp=null;break;case`pointerover`:case`pointerout`:yp.delete(t.pointerId);break;case`gotpointercapture`:case`lostpointercapture`:bp.delete(t.pointerId)}}function wp(e,t,n,r,i,a){return e===null||e.nativeEvent!==a?(e={blockedOn:t,domEventName:n,eventSystemFlags:r,nativeEvent:a,targetContainers:[i]},t!==null&&(t=Et(t),t!==null&&ap(t)),e):(e.eventSystemFlags|=r,t=e.targetContainers,i!==null&&t.indexOf(i)===-1&&t.push(i),e)}function Tp(e,t,n,r,i){switch(t){case`focusin`:return gp=wp(gp,e,t,n,r,i),!0;case`dragenter`:return _p=wp(_p,e,t,n,r,i),!0;case`mouseover`:return vp=wp(vp,e,t,n,r,i),!0;case`pointerover`:var a=i.pointerId;return yp.set(a,wp(yp.get(a)||null,e,t,n,r,i)),!0;case`gotpointercapture`:return a=i.pointerId,bp.set(a,wp(bp.get(a)||null,e,t,n,r,i)),!0}return!1}function Ep(e){var t=Tt(e.target);if(t!==null){var n=l(t);if(n!==null){if(t=n.tag,t===13){if(t=u(n),t!==null){e.blockedOn=t,mt(e.priority,function(){op(n)});return}}else if(t===31){if(t=d(n),t!==null){e.blockedOn=t,mt(e.priority,function(){op(n)});return}}else if(t===3&&n.stateNode.current.memoizedState.isDehydrated){e.blockedOn=n.tag===3?n.stateNode.containerInfo:null;return}}}e.blockedOn=null}function Dp(e){if(e.blockedOn!==null)return!1;for(var t=e.targetContainers;0<t.length;){var n=dp(e.nativeEvent);if(n===null){n=e.nativeEvent;var r=new n.constructor(n.type,n);un=r,n.target.dispatchEvent(r),un=null}else return t=Et(n),t!==null&&ap(t),e.blockedOn=n,!1;t.shift()}return!0}function Op(e,t,n){Dp(e)&&n.delete(t)}function kp(){hp=!1,gp!==null&&Dp(gp)&&(gp=null),_p!==null&&Dp(_p)&&(_p=null),vp!==null&&Dp(vp)&&(vp=null),yp.forEach(Op),bp.forEach(Op)}function Ap(e,n){e.blockedOn===n&&(e.blockedOn=null,hp||(hp=!0,t.unstable_scheduleCallback(t.unstable_NormalPriority,kp)))}var jp=null;function Mp(e){jp!==e&&(jp=e,t.unstable_scheduleCallback(t.unstable_NormalPriority,function(){jp===e&&(jp=null);for(var t=0;t<e.length;t+=3){var n=e[t],r=e[t+1],i=e[t+2];if(typeof r!=`function`){if(pp(r||n)===null)continue;break}var a=Et(n);a!==null&&(e.splice(t,3),t-=3,ks(a,{pending:!0,data:i,method:n.method,action:r},r,i))}}))}function Np(e){function t(t){return Ap(t,e)}gp!==null&&Ap(gp,e),_p!==null&&Ap(_p,e),vp!==null&&Ap(vp,e),yp.forEach(t),bp.forEach(t);for(var n=0;n<xp.length;n++){var r=xp[n];r.blockedOn===e&&(r.blockedOn=null)}for(;0<xp.length&&(n=xp[0],n.blockedOn===null);)Ep(n),n.blockedOn===null&&xp.shift();if(n=(e.ownerDocument||e).$$reactFormReplay,n!=null)for(r=0;r<n.length;r+=3){var i=n[r],a=n[r+1],o=i[_t]||null;if(typeof a==`function`)o||Mp(n);else if(o){var s=null;if(a&&a.hasAttribute(`formAction`)){if(i=a,o=a[_t]||null)s=o.formAction;else if(pp(i)!==null)continue}else s=o.action;typeof s==`function`?n[r+1]=s:(n.splice(r,3),r-=3),Mp(n)}}}function Pp(){function e(e){e.canIntercept&&e.info===`react-transition`&&e.intercept({handler:function(){return new Promise(function(e){return i=e})},focusReset:`manual`,scroll:`manual`})}function t(){i!==null&&(i(),i=null),r||setTimeout(n,20)}function n(){if(!r&&!navigation.transition){var e=navigation.currentEntry;e&&e.url!=null&&navigation.navigate(e.url,{state:e.getState(),info:`react-transition`,history:`replace`})}}if(typeof navigation==`object`){var r=!1,i=null;return navigation.addEventListener(`navigate`,e),navigation.addEventListener(`navigatesuccess`,t),navigation.addEventListener(`navigateerror`,t),setTimeout(n,100),function(){r=!0,navigation.removeEventListener(`navigate`,e),navigation.removeEventListener(`navigatesuccess`,t),navigation.removeEventListener(`navigateerror`,t),i!==null&&(i(),i=null)}}}function Fp(e){this._internalRoot=e}Ip.prototype.render=Fp.prototype.render=function(e){var t=this._internalRoot;if(t===null)throw Error(s(409));var n=t.current;np(n,pu(),e,t,null,null)},Ip.prototype.unmount=Fp.prototype.unmount=function(){var e=this._internalRoot;if(e!==null){this._internalRoot=null;var t=e.containerInfo;np(e.current,2,null,e,null,null),bu(),t[vt]=null}};function Ip(e){this._internalRoot=e}Ip.prototype.unstable_scheduleHydration=function(e){if(e){var t=pt();e={blockedOn:null,target:e,priority:t};for(var n=0;n<xp.length&&t!==0&&t<xp[n].priority;n++);xp.splice(n,0,e),n===0&&Ep(e)}};var Lp=r.version;if(Lp!==`19.2.8`)throw Error(s(527,Lp,`19.2.8`));D.findDOMNode=function(e){var t=e._reactInternals;if(t===void 0)throw typeof e.render==`function`?Error(s(188)):(e=Object.keys(e).join(`,`),Error(s(268,e)));return e=p(t),e=e===null?null:m(e),e=e===null?null:e.stateNode,e};var Rp={bundleType:0,version:`19.2.8`,rendererPackageName:`react-dom`,currentDispatcherRef:E,reconcilerVersion:`19.2.8`};if(typeof __REACT_DEVTOOLS_GLOBAL_HOOK__<`u`){var zp=__REACT_DEVTOOLS_GLOBAL_HOOK__;if(!zp.isDisabled&&zp.supportsFiber)try{We=zp.inject(Rp),Ge=zp}catch{}}e.createRoot=function(e,t){if(!c(e))throw Error(s(299));var n=!1,r=``,i=Qs,a=$s,o=ec;return t!=null&&(!0===t.unstable_strictMode&&(n=!0),t.identifierPrefix!==void 0&&(r=t.identifierPrefix),t.onUncaughtError!==void 0&&(i=t.onUncaughtError),t.onCaughtError!==void 0&&(a=t.onCaughtError),t.onRecoverableError!==void 0&&(o=t.onRecoverableError)),t=ep(e,1,!1,null,null,n,r,null,i,a,o,Pp),e[vt]=t.current,Sd(e),new Fp(t)}})),c=e(((e,t)=>{function n(){if(!(typeof __REACT_DEVTOOLS_GLOBAL_HOOK__>`u`||typeof __REACT_DEVTOOLS_GLOBAL_HOOK__.checkDCE!=`function`))try{__REACT_DEVTOOLS_GLOBAL_HOOK__.checkDCE(n)}catch(e){console.error(e)}}n(),t.exports=s()})),l=n(),u=c();async function d(e){if(e.status===401){let e=window.location.pathname+window.location.search+window.location.hash;return window.location.href=`/login.php?return_to=`+encodeURIComponent(e),new Promise(()=>void 0)}return await e.json()}async function f(){return d(await fetch(`/api/session.php`,{credentials:`same-origin`}))}async function p(e){return d(await fetch(e,{credentials:`same-origin`}))}async function m(e,t){return d(await fetch(e,{method:`POST`,credentials:`same-origin`,headers:{"Content-Type":`application/json`,"X-CSRF-TOKEN":String(t.csrf_token||``)},body:JSON.stringify(t)}))}function h(e){let t=Math.floor((Date.now()-new Date(e).getTime())/1e3);return Number.isNaN(t)||t<60?`just now`:t<3600?Math.floor(t/60)+`m ago`:t<86400?Math.floor(t/3600)+`h ago`:Math.floor(t/86400)+`d ago`}function g(e){let t=new Date(e);return Number.isNaN(t.getTime())?e:t.toLocaleString(void 0,{day:`2-digit`,month:`short`,year:`numeric`,hour:`2-digit`,minute:`2-digit`})}var _={billing:`Billing`,technical:`Technical`,account:`Account`,general:`General`,feedback:`Feedback`,beta_request:`Beta Program Request`},v={open:`Open`,in_progress:`In Progress`,resolved:`Resolved`,closed:`Closed`};function y(){try{let e=localStorage.getItem(`sp-theme`);if(e===`light`||e===`dark`)return e}catch{}return window.matchMedia&&window.matchMedia(`(prefers-color-scheme: light)`).matches?`light`:`dark`}function b(e,t){if(document.documentElement.setAttribute(`data-theme`,e),document.documentElement.className=e===`light`?`light-theme`:`dark-theme`,t)try{localStorage.setItem(`sp-theme`,e)}catch{}}var x={setup:`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">First Time Setup Guide</h1>
            <p style="margin:0;color:var(--text-secondary);">Connect Twitch, mod the bot, and configure the essentials.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="setup" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What is BotOfTheSpecter?</h2>
    <p>
        BotOfTheSpecter is a <strong>cloud-based Twitch chat bot</strong> that runs entirely on our servers.
        You don't need to install any software, run servers, or manage technical infrastructure.
        Just connect your Twitch account and start using the bot immediately!
    </p>

    <hr class="sp-divider">

    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Access the Dashboard</h4>
            <p>Go to the BotOfTheSpecter dashboard:</p>
            <p style="margin:1rem 0;">
                <a href="https://dashboard.botofthespecter.com" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Dashboard
                </a>
            </p>
            <p>Or visit: <code>https://dashboard.botofthespecter.com</code></p>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Connect Your Twitch Account</h4>
            <ol>
                <li>Click the <strong>Login with Twitch</strong> button on the dashboard</li>
                <li>You'll be redirected to Twitch's authorization page</li>
                <li>Review the permissions and click <strong>Authorize</strong></li>
                <li>You'll be redirected back to the dashboard, now logged in</li>
            </ol>
            <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Permissions:</strong> When you authorize BotOfTheSpecter, you'll be asked to grant a number of permissions.
                    These cover moderation, channel management, chat, subscriptions, analytics, and more.
                    Each permission enables specific features that make your streaming experience better.
                    <details style="margin-top:0.75rem;">
                        <summary style="cursor:pointer;color:var(--accent);">View full permissions list</summary>
                        <ul style="margin-top:0.75rem;font-size:0.875rem;max-height:280px;overflow-y:auto;padding-right:0.5rem;">
                            <li>Delete chat messages in channels where you have the moderator role</li>
                            <li>Read unban requests in channels where you have the moderator role</li>
                            <li>Perform moderation actions in a channel</li>
                            <li>Grant or remove the moderator role from users in your channel</li>
                            <li>Edit your channel's broadcast configuration including extension activations</li>
                            <li>Manage Channel Points custom rewards and their redemptions on your channel</li>
                            <li>Manage your channel's broadcast configuration, including updating channel configuration and managing stream markers and stream tags</li>
                            <li>Read your list of follows</li>
                            <li>Send live Stream Chat and Rooms messages</li>
                            <li>Read the list of VIPs in your channel</li>
                            <li>Grant or remove the VIP role from users in your channel</li>
                            <li>Get the details of your subscription to a channel</li>
                            <li>Send announcements in channels where you have the moderator role</li>
                            <li>Get a list of all users on your block list / Add and remove users from your block list</li>
                            <li>Get your Twitch user ID, username, profile image, profile update date, email address, and email verification status</li>
                            <li>Manage your channel's polls</li>
                            <li>Read chat messages from suspicious users and see users flagged as suspicious in channels where you have the moderator role</li>
                            <li>Read your channel's Hype Train data</li>
                            <li>View Channel Points rewards and their redemptions on your channel</li>
                            <li>Get a list of all subscribers to your channel and check if a user is subscribed to your channel</li>
                            <li>Manage your channel's schedule, including adding, updating, and deleting segments</li>
                            <li>Create clips from a broadcast or video</li>
                            <li>Join your channel's chat as a bot user</li>
                            <li>Read the list of channels you have moderator privileges in</li>
                            <li>Read non-private blocked terms / chat settings / moderators / bans / deleted messages / warnings in channels where you have the moderator role</li>
                            <li>Join chat as your user and appear as a bot</li>
                            <li>Manage AutoMod in channels where you have the moderator role</li>
                            <li>Read charity campaign details and user donations on your channel</li>
                            <li>Read chat messages and appear in chat / write chat messages as your user</li>
                            <li>View your channel's moderation data including Moderators, Bans, Timeouts and Automod settings</li>
                            <li>Read the list of followers / chatters in channels where you are a moderator</li>
                            <li>View your channel's Bits information</li>
                            <li>Run ads and manage / read the ads schedule on your channel</li>
                            <li>Ban or unban users / manage shoutouts in channels where you have the moderator role</li>
                        </ul>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Set Up Bot Permissions</h4>
            <p>The bot needs to be a moderator in your channel to function properly. There are two ways to do this:</p>

            <h4><i class="fa-solid fa-star" style="color:var(--accent);"></i> Option 1 (Recommended): Use the dashboard button</h4>
            <p>When you're logged into the dashboard and the bot is not modded, you'll see this warning:</p>
            <p><code>The bot is not a moderator on your channel. Please make the bot a moderator to start it.</code></p>
            <p>Click the <strong>Make Mod</strong> button and follow the prompt.</p>

            <h4 style="margin-top:1rem;">Option 2: Add the role manually on Twitch</h4>
            <ol>
                <li>Go to your <a href="https://dashboard.twitch.tv" target="_blank" rel="noopener">Twitch Dashboard</a></li>
                <li>On the left panel, expand the <strong>Community</strong> menu</li>
                <li>Click <strong>Roles Manager</strong></li>
                <li>Click <strong>Add New</strong></li>
                <li>In the search bar, enter: <code>BotOfTheSpecter</code></li>
                <li>Select the bot user and check the <strong>Moderator</strong> permission</li>
                <li><em>Optional:</em> Also check the <strong>Editor</strong> role to enable VOD video access</li>
            </ol>

            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <strong>Why is this needed?</strong> As a moderator, the bot can:
                    <ul style="margin-top:0.5rem;">
                        <li>Delete inappropriate messages</li>
                        <li>Timeout or ban users when necessary</li>
                        <li>Respond to commands in chat</li>
                        <li>Manage channel point redemptions</li>
                    </ul>
                    <p style="margin-top:0.5rem;margin-bottom:0;"><strong>Editor role benefits:</strong> Allows the bot to access VODs and video content for video-related commands.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Configure the Dashboard</h4>
            <p>Now that the bot has moderator permissions, continue configuration in the dashboard:</p>
            <ul>
                <li>If you used <strong>Option 1 (Make Mod)</strong> in Step 3, you're already in the right place.</li>
                <li>If you used <strong>Option 2 (manual Twitch Roles Manager)</strong>, return to the dashboard and refresh or log out and back in so permissions update.</li>
            </ul>
            <p style="margin:1rem 0;">
                <a href="https://dashboard.botofthespecter.com" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Dashboard
                </a>
            </p>

            <h4>Basic Settings</h4>
            <ul>
                <li><strong>Bot Status:</strong> After the bot is modded, the dashboard detects it automatically. Click <strong>START</strong> to run the bot and wait for the status to update.</li>
                <li><strong>Channel Information:</strong> Set up your preferences on the <a href="https://dashboard.botofthespecter.com/profile.php" target="_blank" rel="noopener">Profile page</a>:
                    <ul style="margin-top:0.5rem;">
                        <li>Technical/advanced options toggle</li>
                        <li>Dashboard language (English, French, or German)</li>
                        <li>Your Time Zone and Weather Location</li>
                        <li>HypeRate.io integration for heart rate display in chat</li>
                        <li>External connections for Discord, Spotify, and StreamElements</li>
                    </ul>
                </li>
                <li><strong>Command Prefix:</strong> Commands use <code>!</code> - this cannot be changed.</li>
            </ul>

            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-gamepad"></i>
                <div>
                    <strong>Control Your Bot</strong><br>
                    BotOfTheSpecter is designed with control in mind - <strong>you run the bot, you stop the bot</strong>.
                    If you no longer wish to use it, simply click <strong>STOP</strong>. It's that simple.<br><br>
                    <em style="display:block;padding-left:0.75rem;border-left:3px solid var(--accent);margin-top:0.5rem;">
                        "I built Specter so I'm not running 4 different chat bots on my own stream, now I just run one, that's Specter." - Developer
                    </em>
                </div>
            </div>

            <h4 style="margin-top:1.25rem;">Moderation Settings</h4>
            <p>Configure moderation on the <a href="https://dashboard.botofthespecter.com/modules.php" target="_blank" rel="noopener">Modules page</a>:</p>
            <ul>
                <li><strong>Joke Blacklist:</strong> Set up joke categories to blacklist from the <code>!joke</code> command</li>
                <li><strong>Chat Protection:</strong> Enable/disable URL blocking in chat
                    <ul style="margin-top:0.5rem;">
                        <li>When enabled, you can whitelist specific links to allow them</li>
                        <li>When disabled, you can still blacklist links that will always be removed from chat</li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">5</div>
        <div class="sp-step-body">
            <h4>Set Up Bot Points</h4>
            <p>Bot Points are your built-in loyalty system. Viewers earn points as they chat and interact, and you can tune how rewards are given. This feature is enabled by default.</p>
            <ul>
                <li><strong>Point Name:</strong> Choose a custom name for your points (e.g. Coins, Tokens, Credits)</li>
                <li><strong>Earning Rates:</strong> Configure how many points users earn for:
                    <ul style="margin-top:0.5rem;">
                        <li>Each chat message sent</li>
                        <li>Following your channel</li>
                        <li>Subscribing to your channel</li>
                        <li>Each cheered message</li>
                        <li>Each viewer in a raid</li>
                    </ul>
                </li>
                <li><strong>Subscriber Multipliers:</strong> Add bonus multipliers for subscribers (e.g. 2x points)</li>
            </ul>

            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <strong>Why set this up?</strong> Bot Points help encourage chat activity and make your community feel more interactive.
                    As more point-based features roll out, you'll be able to use them for custom rewards, shoutouts, and other perks.
                </div>
            </div>

            <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
                <i class="fa-solid fa-code"></i>
                <div>
                    <strong>API Integrations (Credit / Debit):</strong> Manage user points via the API for custom integrations.<br>
                    <ul style="margin-top:0.5rem;">
                        <li><strong>CREDIT</strong> (adds points): <code>https://api.botofthespecter.com/user-points/credit?api_key=1234&amp;username=test&amp;amount=1</code></li>
                        <li><strong>DEBIT</strong> (removes points): <code>https://api.botofthespecter.com/user-points/debit?api_key=1234&amp;username=test&amp;amount=1&amp;allow_negative=false</code></li>
                    </ul>
                    <p style="margin-top:0.5rem;margin-bottom:0;">
                        API Docs:
                        <a href="https://api.botofthespecter.com/docs?v=1&amp;op=POST%20/user-points/credit" target="_blank" rel="noopener">credit_user_points</a>
                        |
                        <a href="https://api.botofthespecter.com/docs?v=1&amp;op=POST%20/user-points/debit" target="_blank" rel="noopener">debit_user_points</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">6</div>
        <div class="sp-step-body">
            <h4>Customize Your Bot</h4>
            <h4>Custom Commands</h4>
            <p>Custom commands are one of the quickest ways to make your bot feel like part of your community. Start with simple commands like <code>!discord</code>, <code>!youtube</code>, <code>!instagram</code>, and <code>!business</code>.</p>
            <p>Create them on the <a href="https://dashboard.botofthespecter.com/custom_commands.php" target="_blank" rel="noopener">Custom Commands page</a>.</p>
            <ul>
                <li><strong>Community links:</strong> Discord, social accounts, merch, and support links</li>
                <li><strong>Channel info:</strong> Stream schedule, PC specs, and FAQs</li>
                <li><strong>Utility commands:</strong> Rules reminder, business email, event announcements</li>
            </ul>
            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <strong>Want to level up your commands?</strong> Use <strong>Custom Variables</strong> to add dynamic, personalized responses.
                    Note: Custom Variables only work in the response part of your command.
                </div>
            </div>

            <h4 style="margin-top:1.5rem;">Auto Messages</h4>
            <p>Auto messages keep important information visible without needing a moderator to post manually. Create them on the <a href="https://dashboard.botofthespecter.com/timed_messages.php" target="_blank" rel="noopener">Timed Messages page</a>.</p>
            <ul>
                <li><strong>Welcome and rules:</strong> Friendly reminders about chat rules and stream expectations</li>
                <li><strong>Useful links:</strong> Discord invite, social links, donation links, and command list</li>
                <li><strong>Stream engagement:</strong> Follow reminder, schedule updates, and community events</li>
            </ul>
            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Timed messages are sent automatically while your channel is <strong>online</strong>, in three ways:
                    <ol style="margin-top:0.5rem;">
                        <li>After a set time interval</li>
                        <li>After a certain number of chat messages (line triggers)</li>
                        <li>After a certain number of chat messages combined with a time delay</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-comment-slash"></i> Bot Not Appearing in Chat</div>
            <div class="sp-card-body">
                <ul>
                    <li>Check that the bot is turned on in the dashboard</li>
                    <li>Verify the bot is added as a moderator</li>
                    <li>Try refreshing the dashboard and re-starting the bot</li>
                    <li>Check the bot status indicator - ONLINE/OFFLINE</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-terminal"></i> Commands Not Working</div>
            <div class="sp-card-body">
                <ul>
                    <li>Ensure you're using the correct prefix - the bot uses <code>!</code></li>
                    <li>Check if the command is enabled in settings</li>
                    <li>Verify the user has permission to use it</li>
                    <li>Some commands require premium features</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-right-to-bracket"></i> Login Issues</div>
            <div class="sp-card-body">
                <ul>
                    <li>Try logging out and back in</li>
                    <li>Clear your browser cache and cookies</li>
                    <li>All modern browsers are supported</li>
                    <li>Check if Twitch is experiencing issues at <a href="https://status.twitch.tv/" target="_blank" rel="noopener">status.twitch.tv</a></li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-shield-halved"></i> Permission Errors</div>
            <div class="sp-card-body">
                <ul>
                    <li>Ensure the bot is a moderator in your channel</li>
                    <li>Only the broadcaster can start and stop the bot - the moderator dashboard does not have this capability</li>
                    <li>Some features require VIP or subscriber status - check the user has the appropriate role</li>
                </ul>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Setup Complete!</h2>
    <div class="sp-alert sp-alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <div>
            <strong>Congratulations!</strong> Your Specter is now set up and running. Once started, the bot automatically joins your channel and remains available 24/7.<br><br>
            <strong>Next Steps:</strong>
            <ul style="margin-top:0.5rem;">
                <li>Explore the dashboard to discover all available features</li>
                <li>Customize commands and settings to match your stream style</li>
                <li>Check out the documentation for advanced features</li>
                <li>Join our Discord for community support and tips</li>
            </ul>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Premium Features</h2>
    <p>Some advanced features require a premium subscription:</p>
    <ul>
        <li><strong>AI Chat:</strong> Have conversations with an AI in your chat</li>
        <li><strong>Advanced Music:</strong> Use <code>!song</code> without connecting Spotify</li>
        <li><strong>Shared Bot Name (BotOfTheSpecter):</strong> The default shared bot username used across the platform</li>
        <li><strong>Custom Bot Name (Experimental/Coming Soon):</strong> Use your own bot username instead of BotOfTheSpecter</li>
    </ul>
    <p style="margin-top:1rem;">Support the developer on Twitch to unlock these features!</p>
    <p>
        <a href="https://twitch.tv/gfaUnDead" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
            <i class="fa-brands fa-twitch"></i> Support on Twitch
        </a>
    </p>

    <hr class="sp-divider">

    <h2>Need Help?</h2>
    <p>If you encounter issues during setup, don't hesitate to reach out:</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1rem;">
        <div class="sp-card" style="text-align:center;">
            <div class="sp-card-body">
                <i class="fa-brands fa-discord" style="font-size:2rem;color:var(--accent);margin-bottom:0.75rem;display:block;"></i>
                <h4 style="margin-bottom:0.5rem;">Discord Support</h4>
                <p>Join our community Discord for real-time help and support.</p>
                <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener" class="sp-btn sp-btn-primary sp-btn-sm">
                    <i class="fa-brands fa-discord"></i> Join Discord
                </a>
            </div>
        </div>
        <div class="sp-card" style="text-align:center;">
            <div class="sp-card-body">
                <i class="fa-solid fa-envelope" style="font-size:2rem;color:var(--blue);margin-bottom:0.75rem;display:block;"></i>
                <h4 style="margin-bottom:0.5rem;">Email Support</h4>
                <p>Send us a detailed message about your issue.</p>
                <a href="mailto:questions@botofthespecter.com" class="sp-btn sp-btn-secondary sp-btn-sm">
                    <i class="fa-solid fa-envelope"></i> Email Us
                </a>
            </div>
        </div>
        <div class="sp-card" style="text-align:center;">
            <div class="sp-card-body">
                <i class="fa-brands fa-twitch" style="font-size:2rem;color:var(--green);margin-bottom:0.75rem;display:block;"></i>
                <h4 style="margin-bottom:0.5rem;">Live Support</h4>
                <p>Catch us live on Twitch for immediate assistance.</p>
                <a href="https://twitch.tv/gfaUnDead" target="_blank" rel="noopener" class="sp-btn sp-btn-secondary sp-btn-sm">
                    <i class="fa-brands fa-twitch"></i> Watch Live
                </a>
            </div>
        </div>
    </div>
`,features:`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Main Features</h1>
            <p style="margin:0;color:var(--text-secondary);">Chat tools, games, events, tracking, and third-party integrations.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="features" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Chat Protection &amp; Custom Commands</h2>

    <h3>Link Protection</h3>
    <p>URL blocking is disabled by default. You must enable it before the bot will remove links posted by non-moderators. Moderators &amp; Broadcasters are always exempt.</p>
    <ul>
        <li>When enabled, any viewer who posts a link will have their message deleted.</li>
        <li>Use <code>!permit @username</code> to give a viewer a 30-second window to post one link.</li>
        <li>Toggle URL blocking from <strong>Integrations → Specter Modules → Chat Protection</strong> in the dashboard.</li>
        <li>Add trusted domains to the <strong>Link Whitelist</strong> so they always bypass URL blocking, without needing a permit.</li>
    </ul>

    <h3>Blocked Terms <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></h3>
    <p>Separately from link blocking, you can keep a list of forbidden words. Any message containing a blocked term is deleted automatically. Configure it under <strong>Integrations → Specter Modules → Chat Protection</strong> in the dashboard.</p>

    <h3>Custom Commands</h3>
    <p>Create unlimited custom commands from the dashboard or directly in chat.</p>
    <ul>
        <li><code>!addcommand !name response</code> - creates a new command.</li>
        <li><code>!editcommand !name new response</code> - updates an existing command.</li>
        <li><code>!removecommand !name</code> - deletes a command.</li>
        <li>Give a command a permission level by quoting the response: <code>!addcommand !name "response text" mod</code> (levels: everyone, mod, vip, subscribers, broadcaster). <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></li>
    </ul>
    <p>Responses support the full <a href="#" data-goto="variables">variables system</a> - including <code>(user)</code>, <code>(count)</code>, <code>(customapi.URL)</code>, math expressions, and more.</p>
    <p>Commands can also have aliases - extra trigger words that redirect to the same command and share its cooldown. <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></p>

    <h3>Timed Messages</h3>
    <p>Schedule messages to post automatically at a set interval. Manage them under <strong>Commands → Timed Messages</strong>.</p>
    <ul>
        <li>Set the interval in minutes and a minimum chat-line threshold so messages only fire when chat is active.</li>
        <li>Multiple timed messages can run simultaneously.</li>
        <li>Supports the same dynamic variables as custom commands.</li>
    </ul>

    <h3>Bot Modes</h3>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Mode</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><strong>Standard</strong></td><td>The main BotOfTheSpecter account joins your channel.</td></tr>
                <tr><td><strong>Custom Bot</strong></td><td>Use your own Twitch bot account - the bot acts with your bot's identity.</td></tr>
                <tr><td><strong>Self Mode</strong></td><td>The broadcaster's own account is used as the bot.</td></tr>
            </tbody>
        </table>
    </div>

    <hr class="sp-divider">

    <h2>Games &amp; Fun</h2>

    <h3>Points-Based Games</h3>
    <p>These games consume or award bot points. The points system must be enabled in <strong>Settings → Bot Points</strong>.</p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Command</th><th>Description</th></tr></thead>
            <tbody>
                <tr>
                    <td><code>!gamble &lt;type&gt; [choice] [amount]</code></td>
                    <td>Wagers bot points (defaults to 100 if omitted). Types: <code>coinflip</code> (50% to win double), <code>blackjack</code> (random 1-21, only 21 wins double), <code>roulette</code> (choose red/black for double or lose).<br><strong>Examples:</strong> <code>!gamble coinflip 100</code> | <code>!gamble roulette red 100</code><br><span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span> On the beta bot, the broadcaster can wager any amount without needing enough points first.</td>
                </tr>
                <tr>
                    <td><code>!slots</code></td>
                    <td>Spins a slot machine with a 70% chance to win. Symbols have values: 🍒=10, 🍋=15, 🍊=20, 🍉=25, 🍇=30, 🍓=35, ⭐=50. Winning spins pay out triple the matched symbol's value; losing spins (the other 30%) deduct the combined value of the three symbols shown (points never drop below zero).</td>
                </tr>
                <tr>
                    <td><code>!roulette</code></td>
                    <td>Russian roulette - survive or get shot. If shot, deducts 100 points as hospital penalty. No chat timeout applied.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3>Social &amp; Party Commands</h3>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Command</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>!rps &lt;rock|paper|scissors&gt;</code></td><td>Play rock-paper-scissors against the bot. Announces win/tie/lose with no points exchanged.</td></tr>
                <tr><td><code>!story &lt;5 words&gt;</code></td><td>AI generates a short story (3-5 sentences) seeded from your five words.<br><strong>Example:</strong> <code>!story dragon castle brave knight magic</code></td></tr>
                <tr><td><code>!joke</code></td><td>Fetches a random joke with category filtering based on your blacklist settings.</td></tr>
                <tr><td><code>!kill @user</code></td><td>Playfully "kills" a viewer or yourself with randomized messages from external API templates.</td></tr>
                <tr><td><code>!hug @user</code></td><td>Sends a virtual hug, increments hug counter, and announces the updated total. Self-targeting blocked; hugging the bot returns the hug to you.</td></tr>
                <tr><td><code>!highfive @user</code></td><td>High-fives a viewer, increments counter, and announces the total. Self-targeting blocked.</td></tr>
                <tr><td><code>!kiss @user</code></td><td>Sends a kiss, increments kiss counter, and announces the total. Self-targeting blocked.</td></tr>
                <tr><td><code>!puzzles</code></td><td>Reports the number of Tanggle puzzles completed by the channel.</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Raffle System <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></h3>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Command</th><th>Who</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>!createraffle &lt;name&gt; &lt;prize&gt; &lt;winners&gt; [weighted]</code></td><td>Moderator</td><td>Creates a scheduled raffle with specified name, prize, and number of winners. Optional weighted mode applies multipliers based on subscriber tier or VIP status.</td></tr>
                <tr><td><code>!startraffle [raffle_id]</code></td><td>Moderator</td><td>Starts a scheduled raffle. If no ID specified, starts the oldest scheduled raffle.</td></tr>
                <tr><td><code>!joinraffle</code></td><td>Viewer</td><td>Enters the active raffle. Respects settings for subscriber-only, follower-only, and moderator exclusions. Weighted raffles apply multipliers automatically.</td></tr>
                <tr><td><code>!leaveraffle</code></td><td>Viewer</td><td>Removes the viewer from the current raffle.</td></tr>
                <tr><td><code>!stopraffle</code></td><td>Moderator</td><td>Ends the running raffle without drawing winners.</td></tr>
                <tr><td><code>!drawraffle [raffle_id]</code></td><td>Moderator</td><td>Performs weighted random selection to pick winners, announces results in chat, and triggers overlay alerts.</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Lotto System</h3>
    <p>A Channel Points-based lotto system where viewers redeem Channel Point rewards to get their lotto numbers. Winning numbers are automatically generated when the stream goes live, and moderators draw prizes across multiple divisions, awarding <strong>Bot Points</strong> to the winners.</p>
    <h4>Setup</h4>
    <ol>
        <li>Create a Channel Point reward on Twitch for lotto entry.</li>
        <li>In the Specter dashboard, sync your Channel Point rewards.</li>
        <li>Edit the lotto reward and add <code>(lotto)</code> to the custom message - this generates the viewer's lotto numbers.</li>
        <li>Viewers redeem the reward to receive their numbers and enter the draw.</li>
    </ol>
    <h4>Commands</h4>
    <ul>
        <li><code>!startlotto</code> (Moderator) - manually generates the winning numbers (they are also generated automatically when the stream goes live).</li>
        <li><code>!drawlotto</code> (Moderator) - compares entries to winning numbers and awards <strong>Bot Points</strong> by division: Division 1 (100,000), Division 2 (50,000), Division 3 (10,000), Division 4 (5,000), Division 5 (1,000), Division 6 (500).</li>
    </ul>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Note:</strong> Winning lotto numbers are automatically generated when your stream goes online. The <code>(lotto)</code> variable in Channel Point reward messages automatically generates and displays the viewer's lotto numbers when they redeem the reward.
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Events &amp; Alerts</h2>
    <p>BotOfTheSpecter reacts to Twitch events automatically. All response messages are customisable from <strong>Settings → Alerts</strong> in the dashboard and support the <a href="#" data-goto="variables">variables system</a>.</p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Event</th><th>What happens</th></tr></thead>
            <tbody>
                <tr><td><strong>New Follow</strong></td><td>Posts a customisable thank-you message and triggers an overlay alert.</td></tr>
                <tr><td><strong>Subscription</strong></td><td>Posts a sub message. Supports <code>(tier)</code> and <code>(months)</code> variables.</td></tr>
                <tr><td><strong>Re-subscription</strong></td><td>Handles resub messages with streak and total month data.</td></tr>
                <tr><td><strong>Gift Subscriptions</strong></td><td>Single and bulk gifts supported. Supports <code>(count)</code> and <code>(total-gifted)</code>. Pay-it-forward gifts also support <code>(gifter)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span>.</td></tr>
                <tr><td><strong>Sub Gift Pay-It-Forward &amp; Upgrades <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></strong></td><td>Extra chat alerts when a viewer pays a gifted sub forward, or upgrades a gifted or Prime sub to a paid subscription.</td></tr>
                <tr><td><strong>Bits / Cheer</strong></td><td>Thanks the viewer. Supports <code>(bits)</code> and <code>(total-bits)</code>.</td></tr>
                <tr><td><strong>Charity Donation</strong></td><td>Thanks the donor in chat for a donation to the channel's active Twitch charity campaign.</td></tr>
                <tr><td><strong>Raid</strong></td><td>Welcomes the raider, triggers an automatic Twitch shoutout. Supports <code>(viewers)</code>.</td></tr>
                <tr><td><strong>Channel Points Redemption</strong></td><td>Executes a custom response per reward - supports TTS, lotto, fortune, and API calls. VIP grants are also supported <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span>.</td></tr>
                <tr><td><strong>Hype Train</strong></td><td>Announces the start and end of the Hype Train. Supports <code>(level)</code>.</td></tr>
                <tr><td><strong>Channel Goal <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></strong></td><td>Broadcasts overlay updates when a channel goal begins, progresses, and ends.</td></tr>
                <tr><td><strong>Ad Break</strong></td><td>Sends an upcoming ad warning, activates an AI chat companion during the break, then resumes normal mode.</td></tr>
                <tr><td><strong>Poll</strong></td><td>Announces when a poll starts (with a countdown) and posts a message when it ends.</td></tr>
                <tr><td><strong>Shoutout Received</strong></td><td>Announces when another streamer gives the channel a Twitch shoutout.</td></tr>
                <tr><td><strong>Stream Online / Offline</strong></td><td>Starts/stops timed messages, watch-time tracking, and logs stream session data.</td></tr>
                <tr><td><strong>Chat Join / Welcome</strong></td><td>Welcomes returning and new viewers by name (bots are ignored automatically).</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Overlay Alerts</h3>
    <p>Events are forwarded to the WebSocket server and displayed through OBS browser source overlays at:<br><code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code></p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Alert Type</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><strong>Sound Alerts</strong></td><td>Custom audio clips triggered by chat commands or Channel Points.</td></tr>
                <tr><td><strong>Walk-ons</strong></td><td>Personalised audio/video that plays when a specific viewer joins chat.</td></tr>
                <tr><td><strong>Text-to-Speech (TTS)</strong></td><td>AI voice reads viewer messages aloud through the OBS browser source.</td></tr>
                <tr><td><strong>Video Alerts</strong></td><td>Custom video clips triggered by events or commands.</td></tr>
            </tbody>
        </table>
    </div>

    <hr class="sp-divider">

    <h2>Tracking &amp; Stats</h2>

    <h3>Bot Points</h3>
    <p>A built-in loyalty point system earned by chatting and participating in the stream, with bonus points for new follows, subscriptions, cheers, and raids. Configure the name, earn rate, and icon under <strong>Settings → Bot Points</strong>.</p>
    <ul>
        <li><code>!points</code> - check your balance.</li>
        <li><code>!addpoints</code> / <code>!removepoints</code> - moderator manual adjustment.</li>
        <li>Spent playing <code>!gamble</code>, <code>!slots</code>, and <code>!roulette</code>.</li>
        <li><code>!store</code> / <code>!store &lt;item&gt;</code> - browse or redeem rewards from the point store. <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></li>
    </ul>

    <h3>Watch Time</h3>
    <p>Tracks total viewing time per viewer across all streams. Viewers check their own with <code>!watchtime</code>.</p>

    <h3>Lurk Tracking</h3>
    <ul>
        <li><code>!lurk</code> - starts tracking and announces the viewer is lurking.</li>
        <li><code>!unlurk</code> - ends tracking and announces the viewer is back.</li>
        <li><code>!lurking</code> - shows how long the user has been lurking this session.</li>
        <li><code>!lurklead</code> - shows who has the most accumulated all-time lurk time.</li>
        <li><code>!userslurking</code> - shows how many viewers are currently lurking.</li>
    </ul>

    <h3>Death Counter</h3>
    <p>Tracks in-game deaths for the current session.</p>
    <ul>
        <li><code>!deaths</code> - shows the count (anyone).</li>
        <li><code>!deathadd</code> / <code>!death+</code> - adds to the counter (moderator).</li>
        <li><code>!deathremove</code> / <code>!death-</code> - removes from the counter (moderator).</li>
    </ul>

    <h3>Typo Counter</h3>
    <ul>
        <li><code>!typo @user</code> - records a typo for a user (moderator).</li>
        <li><code>!typos [@user]</code> - shows the typo count.</li>
        <li><code>!edittypos @user &lt;n&gt;</code> - sets the count to a specific number (moderator).</li>
        <li><code>!removetypos @user</code> - decrements or resets (moderator).</li>
    </ul>

    <h3>Quotes</h3>
    <ul>
        <li><code>!quote [number]</code> - shows a random quote or one by number.</li>
        <li><code>!quoteadd &lt;text&gt;</code> - saves a new quote (moderator).</li>
        <li><code>!removequote &lt;number&gt;</code> - deletes a quote (moderator).</li>
    </ul>

    <h3>Bits Tracking</h3>
    <ul>
        <li><code>!mybits</code> - shows the user's total all-time bits cheered in the channel.</li>
        <li><code>!cheerleader</code> - shows the all-time top bit cheerer.</li>
    </ul>

    <h3>Follow Age</h3>
    <p><code>!followage [@user]</code> queries the Twitch API to show exactly how long a viewer has been following the channel.</p>

    <h3>Working &amp; Study</h3>
    <p>A productivity overlay for co-working streams.</p>
    <ul>
        <li><code>!task &lt;name&gt;</code> - sets your active task on the overlay.</li>
        <li><code>!done [n]</code> - finishes your active task (or multiple backlog items like <code>!done 2; 4; 5</code>) and awards points.</li>
        <li><code>!later &lt;name&gt;</code> / <code>!soon &lt;name&gt;</code> - queues tasks into your backlog.</li>
        <li><code>!backlog</code> - views your pending tasks.</li>
        <li><code>!personaltimer &lt;minutes&gt; &lt;label&gt;</code> - starts a personal focus timer (also supports cycles like <code>50/10/4</code>).</li>
        <li><code>!project &lt;name&gt;</code> - organises your tasks into specific projects.</li>
        <li><code>!tasktimer</code> - broadcaster/mod controls for the main overlay timer (e.g. <code>!tasktimer auto on</code>).</li>
        <li><code>!taskhelp</code> - outputs a summary of these commands in chat.</li>
    </ul>

    <h3>Subathon Timer</h3>
    <p>A countdown timer that extends with subs and bits (cheers) to drive engagement during subathon events. Check remaining time with <code>!subathon status</code>. Configure time additions per sub tier from the dashboard.</p>

    <hr class="sp-divider">

    <h2>Integrations</h2>
    <p>BotOfTheSpecter connects to a wide range of third-party services to enhance your stream. All integrations are configured from <strong>Integrations</strong> in the dashboard.</p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Service</th><th>What the bot does</th></tr></thead>
            <tbody>
                <tr><td><strong><i class="fa-brands fa-spotify" style="color:#1DB954;"></i> Spotify</strong></td><td>Displays the current track (<code>!song</code>), accepts viewer song requests via Spotify links, search terms, or YouTube links auto-matched to Spotify (<code>!sr</code>), skips tracks (<code>!skipsong</code>), and shows the queue (<code>!songqueue</code>).</td></tr>
                <tr><td><strong><i class="fa-solid fa-fingerprint" style="color:#0088FF;"></i> Shazam</strong></td><td>Identifies the currently playing song by audio fingerprinting when Spotify has no track data - a premium failover for <code>!song</code> (monthly request limit resets on the 23rd).</td></tr>
                <tr><td><strong><i class="fa-brands fa-steam"></i> Steam</strong></td><td>Looks up Steam games by name via the Steam API - shows store descriptions, prices, and app IDs (<code>!steam</code>).</td></tr>
                <tr><td><strong><i class="fa-solid fa-video"></i> OBS WebSocket</strong></td><td>Controls OBS scenes and sources directly from chat via the <code>!obs</code> moderator command.</td></tr>
                <tr><td><strong><i class="fa-solid fa-heart-pulse" style="color:#e74c3c;"></i> HypeRate</strong></td><td>Connects via WebSocket to show the streamer's live BPM in chat with <code>!heartrate</code>.</td></tr>
                <tr><td><strong><i class="fa-solid fa-bolt" style="color:#f5a623;"></i> StreamElements</strong></td><td>Receives tip and merch alert events in real time via Socket.IO and forwards them to overlays.</td></tr>
                <tr><td><strong><i class="fa-solid fa-circle-dollar-to-slot" style="color:#31c3a2;"></i> StreamLabs</strong></td><td>Receives donation and alert events and broadcasts them to the WebSocket overlay server.</td></tr>
                <tr><td><strong><i class="fa-solid fa-mug-hot" style="color:#ff5e5b;"></i> Ko-fi</strong></td><td>Receives donation, subscription, and shop-order webhooks, announces them in chat, and forwards them to overlays.</td></tr>
                <tr><td><strong><i class="fa-brands fa-patreon" style="color:#ff424d;"></i> Patreon</strong></td><td>Receives new-pledge and membership webhooks, announces them in chat, and forwards them to overlays.</td></tr>
                <tr><td><strong><i class="fa-solid fa-shirt" style="color:#9b59b6;"></i> Fourthwall</strong></td><td>Receives order, donation, giveaway, and subscription webhooks, announces them in chat, and forwards them to overlays.</td></tr>
                <tr><td><strong><i class="fa-solid fa-robot" style="color:#3ecf8e;"></i> OpenAI (GPT)</strong></td><td>Powers AI responses in the home channel, generates AI stories (<code>!story</code>), and runs an AI chat companion during ad breaks with persistent chat history.</td></tr>
                <tr><td><strong><i class="fa-solid fa-cloud-sun" style="color:#4aa3f0;"></i> OpenWeatherMap</strong></td><td>Fetches live weather for any location via <code>!weather &lt;city&gt;</code>.</td></tr>
                <tr><td><strong><i class="fa-solid fa-user-tag"></i> Pronouns (alejo.io)</strong></td><td>Looks up and caches viewer-set pronouns, using them naturally when the bot mentions a viewer by name.</td></tr>
                <tr><td><strong><i class="fa-brands fa-discord" style="color:#5865F2;"></i> Discord</strong></td><td>A companion Discord bot handles stream announcements, reaction roles, support tickets, voice music playback, and Twitch account linking.</td></tr>
                <tr><td><strong><i class="fa-solid fa-microphone"></i> Text-to-Speech (TTS)</strong></td><td>Reads viewer messages through an OBS overlay. <strong>Normal</strong> is a steady English read (Alloy, Ash, Ballad, Coral, Echo, Fable, Nova, Onyx, Sage, Shimmer, Verse). <strong>Expressive</strong> is multilingual, with shouting and laughter from the message text (beta).</td></tr>
                <tr><td><strong><i class="fa-solid fa-ruler-combined" style="color:#f0a500;"></i> Unit &amp; Currency Conversion</strong></td><td>Powered by the Pint unit library for length, weight, temperature, volume, speed, and more - plus live currency exchange rates via the ExchangeRate API - all through <code>!convert</code> (e.g. <code>!convert 10 km mi</code> or <code>!convert $10 USD AUD</code>).</td></tr>
            </tbody>
        </table>
    </div>
`,spotify:`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Setting Up Your Own Spotify Application</h1>
            <p style="margin:0;color:var(--text-secondary);">Create a personal Spotify Developer app and link it to BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="spotify" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Important: Spotify Integration Changes (Effective March 9, 2026)</strong><br>
            We apologise for the inconvenience. Due to Spotify's updated Developer Policy, our platform Spotify client is no longer able to accept new users - Development Mode apps are now capped at 5 authorized users. If you were previously linked via our platform account and need to reconnect, your slot is still reserved. For new users, you will need to create your own Spotify app to use Spotify integration - it takes only a few minutes and will be solely used for your channel. Note: your Spotify developer account must have Spotify Premium to use Development Mode.
        </div>
    </div>

    <div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Don't want to set up Spotify?</strong> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span><br>
            On the beta bot, <code>!song</code>, <code>!songrequest</code> (<code>!sr</code>), and <code>!skipsong</code> (<code>!skip</code>) fall back to the built-in YouTube media queue when no Spotify account is linked, so song requests still work without completing this setup. Linking Spotify (via the platform app or your own, below) upgrades these commands to search and control real Spotify playback.
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Create a Spotify Developer Account</h4>
            <ol>
                <li>Go to the <a href="https://developer.spotify.com/" target="_blank" rel="noopener">Spotify Developer Dashboard</a>.</li>
                <li>Log in with your Spotify account (or create one if you don't have it).</li>
                <li>Accept the terms and conditions.</li>
            </ol>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Create Your Spotify Application</h4>
            <ol>
                <li>Click on <strong>Create app</strong>.</li>
                <li>Fill in the application details:
                    <ul>
                        <li><strong>App name:</strong> <code>Specter-[Your Username]</code> (e.g., <code>Specter-JohnDoe</code>)</li>
                        <li><strong>App description:</strong> Twitch bot integration for Spotify</li>
                        <li><strong>Website:</strong> <code>https://dashboard.botofthespecter.com</code></li>
                        <li><strong>Redirect URI:</strong> <code>https://dashboard.botofthespecter.com/spotifylink.php</code></li>
                    </ul>
                </li>
                <li>Check the box for <strong>Web API</strong> under "Which API/SDKs are you planning to use?"</li>
                <li>Check the agreement boxes and click <strong>Save</strong>.</li>
            </ol>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Get Your App Credentials</h4>
            <ol>
                <li>In your app dashboard, you'll see your <strong>Client ID</strong> displayed.</li>
                <li>Copy the <strong>Client ID</strong> (a 32-character string).</li>
                <li>Click <strong>View client secret</strong> to reveal and copy the <strong>Client Secret</strong>.</li>
            </ol>
            <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Keep your Client Secret secure</strong> - never share it publicly or commit it to version control.
                </div>
            </div>
            <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
                <i class="fa-solid fa-shield-halved"></i>
                <div>
                    <strong>Security Note:</strong> Your credentials are stored securely in our encrypted database and are only used for your bot's Spotify integration.
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Configure BotOfTheSpecter</h4>
            <ol>
                <li>Go to your <a href="https://dashboard.botofthespecter.com/spotifylink.php" target="_blank" rel="noopener">Spotify Link page</a>.</li>
                <li>Check the <strong>Enable Own Client</strong> box.</li>
                <li>Enter your <strong>Client ID</strong> and <strong>Client Secret</strong> in the fields that appear.</li>
                <li>Click <strong>Save Credentials</strong>.</li>
                <li>Click the <strong>Link Spotify Account</strong> button to authorize with your new app.</li>
            </ol>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting Common Issues</h2>
    <ul>
        <li>
            <strong>Redirect URI mismatch:</strong> Ensure the Redirect URI in your Spotify app matches exactly:<br>
            <code>https://dashboard.botofthespecter.com/spotifylink.php</code>
        </li>
        <li>
            <strong>Permissions:</strong> The required scopes (<code>user-read-playback-state</code>, <code>user-modify-playback-state</code>, <code>user-read-currently-playing</code>) are automatically requested during authorization.
        </li>
        <li>
            <strong>Rate limits:</strong> Spotify has rate limits - if you exceed them, wait a moment before trying again.
        </li>
        <li>
            <strong>Authorization fails:</strong> Double-check that your Client ID and Client Secret are correct and that the Redirect URI matches exactly.
        </li>
    </ul>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            Using your own Spotify app gives you more control and potentially higher rate limits, but requires you to manage the app yourself.
        </div>
    </div>
`,tts:`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Text-to-Speech (TTS) Module</h1>
            <p style="margin:0;color:var(--text-secondary);">Read chat and Channel Point messages aloud through your OBS overlay.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="tts" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What is TTS &amp; How to Set It Up</h2>
    <p>The Text-to-Speech (TTS) module reads messages aloud on your stream through the audio overlay. In the dashboard you pick a style and a voice. <strong>Normal</strong> is a steady English read. <strong>Expressive</strong> (beta) is multilingual, shouts ALL CAPS words, and laughs on words like <code>lol</code> instead of speaking them.</p>

    <h3>Setting Up TTS</h3>
    <ol>
        <li>Open the dashboard and go to <strong>Modules → TTS Settings</strong>.</li>
        <li>Choose <strong>Normal</strong> or <strong>Expressive</strong>.</li>
        <li>Pick a voice. The first time you switch to Expressive, <strong>Callum</strong> is selected by default. Expressive has no language picker — it follows the viewer’s text.</li>
        <li>Save TTS settings.</li>
        <li>Add your audio overlay in OBS and enable monitoring — see the <a href="#" data-goto="obs-audio">OBS Audio Monitoring</a> guide.</li>
        <li>Test with a Channel Point redemption or a <code>(tts)</code> variable.</li>
    </ol>

    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            All TTS audio is played through your configured audio overlay. Make sure you have the correct overlay URL in your OBS browser source and audio monitoring enabled.
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Using TTS with Channel Points</h3>
    <p>TTS is triggered through Twitch Channel Points redemptions. Viewers redeem a reward to have their message read with the <strong>style and voice</strong> you saved in TTS settings.</p>

    <h3 style="margin-top:1.25rem;">Using TTS with the Point Store <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></h3>
    <p>On the beta bot, viewers can also trigger TTS by buying a TTS item from your <strong>Point Store</strong>, spending bot loyalty points instead of Twitch Channel Points. Their message uses the same style and voice as Channel Points TTS.</p>

    <hr class="sp-divider">

    <h2>Normal Voices</h2>
    <p>Normal is a steady read. Click play next to each voice to hear a sample:</p>
    <div id="react-tts-normal" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-top:1rem;"></div>

    <hr class="sp-divider">

    <h2>Expressive Voices <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></h2>
    <p>Expressive is multilingual: it follows the language of the message (including mixed-language lines when it can). Words in ALL CAPS with three or more letters are shouted. If Expressive cannot generate a line, that line falls back to your saved Normal voice.</p>
    <p>These laugh words are <strong>not spoken</strong>. The voice laughs instead:</p>
    <ul>
        <li><code>lol</code>, <code>lols</code>, <code>lul</code>, <code>lulz</code></li>
        <li><code>lmao</code>, <code>lmfao</code>, <code>rofl</code></li>
        <li><code>haha</code>, <code>hahaha</code>, <code>hahah</code>, <code>ha ha</code></li>
        <li><code>hehe</code></li>
    </ul>
    <div id="react-tts-expressive"></div>

    <hr class="sp-divider">

    <h2>Troubleshooting TTS</h2>
    <ul>
        <li><strong>No audio output:</strong> Verify that your audio overlay is correctly configured in OBS and that audio monitoring is enabled. See the <a href="#" data-goto="obs-audio">OBS Audio Monitoring</a> guide.</li>
        <li><strong>Wrong voice or style:</strong> In <strong>Modules → TTS Settings</strong>, confirm style (Normal or Expressive), the matching voice, and that you clicked Save. Expressive is beta-only.</li>
        <li><strong>Laugh words still spoken:</strong> Those tokens are stripped only on Expressive. On Normal, <code>lol</code> is read as text.</li>
        <li><strong>Audio too quiet or too loud:</strong> Adjust the volume slider on the audio overlay source in OBS.</li>
        <li><strong>TTS not responding:</strong> Ensure the bot is running (beta if you use Expressive) and the overlay is connected.</li>
    </ul>
`,"obs-audio":`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">OBS Audio Monitoring Setup</h1>
            <p style="margin:0;color:var(--text-secondary);">Hear overlay alerts, TTS, and walk-ons through OBS during your stream.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="obs-audio" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Why Audio Monitoring?</h2>
    <p>Audio monitoring lets you hear audio from your overlays - sound alerts, TTS, and walk-ons - directly through OBS, ensuring they play correctly during your stream.</p>
    <div class="sp-alert sp-alert-info" style="margin:1rem 0;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Before you begin:</strong> Have your overlay URL ready from your Specter Profile page. The format is:<br>
            <code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code>
        </div>
    </div>

    <h2>Part 1: Configure OBS Audio Settings</h2>
    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Open OBS Studio</h4>
            <p>Launch OBS on your computer.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Go to Settings</h4>
            <p>Click the <strong>Settings</strong> button in the bottom-right corner of the OBS window.</p>
            <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Settings_Button.png" alt="OBS Settings Button" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Select the Audio Tab</h4>
            <p>In the Settings window, click the <strong>Audio</strong> tab.</p>
            <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Access_Audio_Settings.png" alt="Access Audio Settings in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Configure Monitoring Device</h4>
            <p>Under <strong>Monitoring Device</strong>, select your desired audio output (e.g., headphones or speakers). Choose <em>Default</em> or your primary device.</p>
            <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Configure_Monitoring_Device.png" alt="Configure Monitoring Device in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Part 2: Add the Overlay Browser Source</h2>
    <div class="sp-step">
        <div class="sp-step-num">5</div>
        <div class="sp-step-body">
            <h4>Add a Browser Source</h4>
            <ol style="margin-top:0.75rem;padding-left:1.25rem;">
                <li>In the <strong>Sources</strong> panel, click <strong>+</strong> and select <strong>Browser</strong>.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source.png" alt="Add New Source in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;"><br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Browser.png" alt="Select Browser Source in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Select <strong>Create new</strong> and give it a name (e.g., <em>Specter Overlay</em>). Ensure <strong>Make source visible</strong> is checked and click <strong>OK</strong>.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Browser_Name_Setting.png" alt="Create New Browser Source in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">In the Properties window, paste your overlay URL into the <strong>URL</strong> field:<br>
                    <code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code><br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Broswer_Properties_Window.png" alt="Browser Source Properties Window in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Check <strong>Control audio via OBS</strong>, clear any text in <strong>Custom CSS</strong>, then click <strong>OK</strong>.</li>
            </ol>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">6</div>
        <div class="sp-step-body">
            <h4>Configure Audio Monitoring for the Browser Source</h4>
            <ol style="margin-top:0.75rem;padding-left:1.25rem;">
                <li>The browser source will appear in the <strong>Audio Mixer</strong> at the bottom of OBS.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Broswer_Source_Audio_Mixer.png" alt="Browser Source in OBS Audio Mixer" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Click the <strong>⋯</strong> (three dots) next to the speaker icon for the browser source.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Broswer_Source_Audio_Mixer_Advanced_Audio_Properties.png" alt="Advanced Audio Properties Menu in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Click <strong>Advanced Audio Properties</strong>.</li>
                <li style="margin-top:0.75rem;">Set the <strong>Audio Monitoring</strong> dropdown to <strong>Monitor and Output</strong>.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Advanced_Audio_Properties_Window.png" alt="Advanced Audio Properties Window in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;"><br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Advanced_Audio_Properties_Window_Saved.png" alt="Monitor and Output Selected" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Click <strong>Close</strong>. Your overlay audio is now configured.</li>
            </ol>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Hearing an echo on sound alerts?</strong> Set Audio Monitoring to <strong>Monitor Only (mute output)</strong> instead of "Monitor and Output". Everyone's audio/sound setup is different - try this first before anything else.
        </div>
    </div>
    <div style="display:grid;gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header">No audio heard at all</div>
            <div class="sp-card-body">Check that your monitoring device is correctly selected in OBS <strong>Settings → Audio → Monitoring Device</strong>.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Echo on stream or sound alerts</div>
            <div class="sp-card-body">In Advanced Audio Properties for the browser source, change <strong>Audio Monitoring</strong> to <strong>Monitor Only (mute output)</strong>.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Overlay URL not working</div>
            <div class="sp-card-body">Make sure the URL contains the correct API key from your Specter Profile page:<br><code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Browser source not monitoring audio</div>
            <div class="sp-card-body">Confirm that <strong>Control audio via OBS</strong> is checked in the browser source Properties, and that Advanced Audio Properties is set to Monitor and Output.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Source appears muted</div>
            <div class="sp-card-body">Check the OBS Audio Mixer for the browser source and confirm the speaker icon is not muted.</div>
        </div>
    </div>
`,variables:`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Variables</h1>
            <p style="margin:0;color:var(--text-secondary);">Dynamic tokens for custom commands, timed messages, channel point rewards, and event alerts.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="variables" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <div class="sp-alert sp-alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            This is the central reference for <strong>every message variable</strong> in BotOfTheSpecter. The same variable-processing engine powers
            <strong>custom commands</strong>, <strong>timed messages</strong>, <strong>channel point rewards</strong>, and <strong>event alerts</strong>
            (welcomes, follows, subs, raids, bits, and ad notices) - so the <strong>Universal Variables</strong> below work in all of them.
            Reward-only and event-only tokens are listed in their own sections further down.
            Variables marked <span style="color:#c813e0;font-weight:600;">purple</span> are beta-only and currently in testing.
        </div>
    </div>

    <h2>Universal Variables</h2>
    <p style="margin:0 0 0.25rem;">These work in <strong>every</strong> message type - custom commands, channel point rewards, and event alerts. <strong>Timed messages</strong> are the exception: on the live bot only <code>(game)</code> is currently replaced in timed messages; full variable support in timed messages is a <span style="color:#c813e0;font-weight:600;">Beta</span> feature.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;margin-top:1.25rem;">

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(count)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Increments and displays the number of times this command has been used.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>This command has been used (count) times!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>This command has been used 42 times!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(usercount)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Displays how many times <em>this specific user</em> has used this command.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) has used this command (usercount) times!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername has used this command 15 times!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code> / <code style="color:#3273dc;">(author)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;"><code>(user)</code> displays the username of the person who triggered the command, or the @mentioned user if one was provided. <code>(author)</code> always refers to the person who typed the command, regardless of any @mention.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Hey (user), welcome to the stream! (author) says hi!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Hey @someone, welcome to the stream! streamername says hi!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(game)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Displays the current game/category being streamed.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>We're currently playing (game)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>We're currently playing Just Chatting!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(daysuntil.DATE)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Calculates the number of days until a specific date. Format: <code>YYYY-MM-DD</code>. Automatically rolls over to the next year if the date has already passed.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Only (daysuntil.2026-12-25) days until Christmas!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Only 42 days until Christmas!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(timeuntil.DATE)</code> / <code style="color:#3273dc;">(timeuntil.DATE-HH-MM)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Calculates the time remaining until a specific date or date and time. Use <code>YYYY-MM-DD</code> for date-only, or <code>YYYY-MM-DD-HH-MM</code> to include a specific time.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>(timeuntil.2026-12-25)</code><br>
                    <code>(timeuntil.2026-06-15-18-00)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>The event starts in 42 days, 12 hours, and 30 minutes!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(math.expression)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Evaluates a simple math expression left-to-right. Supports <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code> - operators apply strictly in the order they appear (no standard order of operations), and parentheses are <strong>not</strong> supported.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>2 + 2 = (math.2+2)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>2 + 2 = 4</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(random.percent)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Generates a random percentage between 0% and 100%. Use <code>(random.percent.X-Y)</code> for a custom range.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>(user) is (random.percent) cool today!</code><br>
                    <code>Your luck today is (random.percent.50-100)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername is 73% cool today!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(random.number)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Generates a random number between 0 and 100. Use <code>(random.number.X-Y)</code> for a custom range.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>Your roll: (random.number)</code><br>
                    <code>You dealt (random.number.1-20) damage!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>You dealt 14 damage!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(random.pick.item1.item2.item3)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Randomly selects one option from a dot-separated inline list.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) should play (random.pick.Minecraft.Fortnite.Valorant) next!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername should play Minecraft next!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(command.name)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">References another custom command and sends its response as an additional message.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Here's some info: (command.socials)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> Response from the <code>socials</code> command</p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(customapi.URL)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Fetches a URL and inserts the plain text response. On the live bot, <code>(customapi.json.URL)</code> fetches JSON and inserts it as raw text just like a normal request. <span style="color:#c813e0;font-weight:600;">Beta:</span> <code style="color:#c813e0;">(customapi.json.URL)</code> instead runs silently (nothing is printed) and stores the JSON in temporary context for use with the Beta <code style="color:#c813e0;">(json.*)</code> variable.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>(customapi.https://api.example.com/joke)</code> - raw response<br>
                    <code>(customapi.json.https://api.example.com/data)</code> - raw JSON text on the live bot; silent Beta context on beta<br>
                    <code>(customapi.https://yourapi.com/user.php?user=(user))</code> - with variable in URL</p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(call.commandname)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Calls and executes a built-in bot command by name.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(call.shoutout)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> Output from the built-in <code>shoutout</code> command</p>
                <p style="margin-top:0.5rem;"><span style="color:#c813e0;font-weight:600;">Beta:</span> pass an argument with <code style="color:#c813e0;">(call.commandname.argument)</code> to forward input to the called command.</p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(if.CONDITION|TRUE|FALSE)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Evaluates a condition and returns one of two values. All other variables are resolved first. Supported operators: <code>=</code> <code>!=</code> <code>&lt;</code> <code>&gt;</code> <code>&lt;=</code> <code>&gt;=</code> <code>contains</code> <code>startswith</code> <code>endswith</code></p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>(if.(arg) = start|The timer has started!|Say start to begin)</code><br>
                    <code>(if.(user) = gfaundead|Welcome boss!|Hello (user)!)</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(arg)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">The argument the user passed after the command name. Empty string if no argument was given.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(author) gives (arg) a big hug!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat</strong> (user types <code>!hug @someone</code>): <code>streamername gives @someone a big hug!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(pronouns)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Displays the user's full pronoun set, fetched from <a href="https://pronouns.alejo.io" target="_blank" rel="noopener">pronouns.alejo.io</a>. Defaults to <code>they/them</code> if not set.</p>
                <p style="margin-top:0.5rem;">Use <code style="color:#c813e0;">(pronouns.they)</code> for just the subject pronoun (e.g. <code>she</code>, <code>he</code>, <code>they</code>) and <code style="color:#c813e0;">(pronouns.them)</code> for just the object pronoun (e.g. <code>her</code>, <code>him</code>, <code>them</code>).</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) and (pronouns) are here! We hope (pronouns.they) enjoy the stream! Give (pronouns.them) a warm welcome!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername and she/her are here! We hope she enjoy the stream! Give her a warm welcome!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(random.pick)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Picks a random item from the pre-configured options list stored in the database for that command. No inline items needed.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Today's winner is (random.pick)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Today's winner is Option2!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(random.pick.list.commandname)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Picks a random item from the options list stored for a <em>different</em> named command. Useful for sharing a single list across multiple commands or rewards.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>The chosen game is (random.pick.list.gamelist)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>The chosen game is Minecraft!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(count.name)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">A named counter shared across commands. Increments by 1 each time it's used (user input never changes the amount), and the same name can be referenced from any command.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>We've died (count.deaths) times!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>We've died 7 times!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(clearcount.name)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Resets a named counter (see <code style="color:#c813e0;">(count.name)</code>) back to 0. Outputs nothing itself.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Deaths reset! (clearcount.deaths)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Deaths reset!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(shoutout.username)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Triggers a shoutout for a <em>specific named user</em> (not whoever ran the command). The shoutout is sent as a separate follow-up message. Unlike the event-only <code style="color:#c813e0;">(shoutout)</code>, this works in any message type.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Go check out (shoutout.someone)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Go check out!</code> <em>(followed by the shoutout for someone)</em></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(json.path.to.value)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Pulls a value out of the JSON fetched by <code>(customapi.json.URL)</code>, using a dot path. Fetch the JSON first (it's silent), then reference fields with <code style="color:#c813e0;">(json.key)</code> or <code style="color:#c813e0;">(json.nested.key)</code>.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(customapi.json.https://api.example.com/user)(user)'s title is (json.data.title)</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(todo.add.category.[description])</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">All message types</span>
                <p style="margin-top:0.5rem;">Silently adds an item to your to-do list under a category. Outputs nothing in chat. <code>CATEGORY</code> must be the numeric ID of one of your to-do categories (from the To-Do List page) - a non-numeric value silently falls back to your first category. Format: <code>(todo.add.CATEGORY.[the item text])</code>.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Noted! (todo.add.2.[fix the overlay flicker])</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Noted!</code></p>
            </div>
        </div>

    </div>

    <hr class="sp-divider">

    <h2>Channel Point Reward Variables</h2>
    <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Tip:</strong> All shared variables from the section above - including <code>(count)</code>, <code>(user)</code>, <code>(author)</code>, <code>(game)</code>, <code>(random.*)</code>, <code>(math.*)</code>, <code>(customapi.*)</code>, <code>(json.*)</code>, <code>(if.*)</code>, <code>(daysuntil.*)</code>, <code>(timeuntil.*)</code>, <code>(pronouns)</code>, <code>(command.*)</code>, <code>(call.*)</code>, and <code>(arg)</code> - also work in channel point reward messages.
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(message)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The text input the user provided when redeeming the reward.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) says: (message)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername says: hello world</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(usercount)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Displays how many times this specific user has redeemed <em>this reward</em>. Uses a separate counter from the command version of <code>(usercount)</code>.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) has redeemed this reward (usercount) times!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername has redeemed this reward 5 times!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(userstreak)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The current consecutive redemption streak for this user. Resets to 1 when a different user redeems the reward.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) is on a (userstreak) streak!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername is on a 3 streak!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(track)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Silently increments the reward's usage counter. Does not display anything in chat.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Thanks for redeeming! (track)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Thanks for redeeming!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(tts)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Triggers text-to-speech using the user's input text. Does not display anything in chat itself.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) triggered TTS! (tts)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername triggered TTS!</code> <em>(user's input is sent to TTS)</em></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(tts.message)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">After all variables are processed, sends the final composed message to both chat <em>and</em> text-to-speech simultaneously.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) says: (message) (tts.message)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername says: hello world</code> <em>(also sent to TTS)</em></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(lotto)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Generates a set of lottery numbers for the redeeming user.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user), your lucky numbers are: (lotto)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername, your lucky numbers are: 7, 14, 22, 35, 42</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(fortune)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Fetches a random fortune for the redeeming user. It does not include their name automatically - combine it with <code>(user)</code> if you want the fortune addressed to them.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user), (fortune)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername, you will find great success today</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(vip)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Grants the redeeming user VIP status via the Twitch API. Does not output any text itself.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Congrats (user), you are now a VIP! (vip)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Congrats streamername, you are now a VIP!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(vip.today)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Same as <code style="color:#c813e0;">(vip)</code>, but also records the user so that VIP status is automatically removed when the stream ends.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) is VIP for today's stream! (vip.today)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername is VIP for today's stream!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(redeem.input)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The text the viewer typed when redeeming the reward (the same value as <code style="color:#c813e0;">(message)</code>).</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) requested: (redeem.input)</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(redeem.title)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The title of the redeemed reward.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) redeemed &quot;(redeem.title)&quot;!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(redeem.cost)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The channel-point cost of the redeemed reward.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>That cost you (redeem.cost) points!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(redeem.prompt)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The reward's prompt / description text.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Reward info: (redeem.prompt)</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(redeem.id)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The unique ID of this redemption. Handy for logging or custom API calls.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(customapi.https://yourapi.com/log?id=(redeem.id))</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(redeem.status)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The redemption's status (e.g. <code>fulfilled</code>, <code>unfulfilled</code>).</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Status: (redeem.status)</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(redeem.redeemed_at)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The timestamp when the reward was redeemed.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Redeemed at (redeem.redeemed_at)</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(storeredeem)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Records this redemption into your stored redeems list - used for <strong>Point Store</strong> fulfillment tracking. Outputs nothing in chat.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Order received! (storeredeem)</code></p>
            </div>
        </div>

    </div>

    <hr class="sp-divider">

    <h2>Event Alert Variables</h2>
    <p>Event-specific tokens for <strong>welcome messages</strong>, <strong>ad notices</strong>, and <strong>Twitch chat alerts</strong> (followers, subs, raids, bits, hype trains). Every <strong>Universal Variable</strong> above also works here - the event-specific tokens below are available <em>in addition</em> to them, because all alert messages run through the same variable-processing engine as custom commands.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
        <i class="fa-solid fa-lightbulb"></i>
        <div>
            <strong>Pro Tip:</strong> You can combine multiple variables in a single message for more dynamic alerts!<br>
            <strong>Example:</strong> <code>Thank you (user) for (bits) bits! You've given a total of (total-bits) bits to the channel!</code><br>
            <strong>In chat:</strong> <code>Thank you BotOfTheSpecter for 100 bits! You've given a total of 5,000 bits to the channel!</code>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">General Variables <span style="font-weight:400;font-size:0.875rem;color:var(--text-secondary);">(available across multiple event modules)</span></h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-top:0.75rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">
                <p>In an alert, <code>(user)</code> resolves to whoever triggered the event - the follower, subscriber, raider, gifter, or cheerer.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Thank you (user) for following!</code></p>
                <p><strong>In chat:</strong> <code>Thank you BotOfTheSpecter for following!</code></p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(shoutout)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <p>Triggers a shoutout for the user. The shoutout info is sent as a separate message after your alert.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Welcome (user)! (shoutout)</code></p>
                <p><strong>In chat:</strong><br>
                    <code>Welcome BotOfTheSpecter!</code><br>
                    <code>Check out their channel at twitch.tv/BotOfTheSpecter - They were last playing Software and Game Development!</code>
                </p>
            </div>
        </div>
    </div>
    <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <code style="color:#c813e0;">(pronouns)</code> (plus <code style="color:#c813e0;">(pronouns.they)</code> / <code style="color:#c813e0;">(pronouns.them)</code>), <code style="color:#c813e0;">(arg)</code>, and <code style="color:#c813e0;">(if.*)</code> also work in alerts - they're documented once under <strong>Universal Variables</strong> above.
        </div>
    </div>

    <hr class="sp-divider">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-hand-wave"></i> Welcome Messages</div>
            <div class="sp-card-body">
                <p style="color:var(--text-secondary);font-size:0.875rem;">No unique variables - all <strong>General Variables</strong> above (including <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, <code style="color:#c813e0;">(pronouns.them)</code>) are available in welcome messages.</p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-rectangle-ad"></i> Ad Notices</div>
            <div class="sp-card-body">
                <div style="margin-bottom:0.75rem;">
                    <div class="sp-card-header" style="padding-left:0;"><code style="color:#3273dc;">(minutes)</code></div>
                    <p style="margin-top:0.35rem;">Shows how many minutes until an upcoming ad break starts. Used in the upcoming ad notification message.</p>
                    <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Heads up! An ad break is coming up in (minutes) minutes!</code></p>
                    <p><strong>In chat:</strong> <code>Heads up! An ad break is coming up in 5 minutes!</code></p>
                </div>
                <div>
                    <div class="sp-card-header" style="padding-left:0;"><code style="color:#3273dc;">(duration)</code></div>
                    <p style="margin-top:0.35rem;">Shows the length of the ad break, formatted as a human-readable string.</p>
                    <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Ad break will last (duration).</code></p>
                    <p><strong>In chat:</strong> <code>Ad break will last 1 minute 30 seconds.</code></p>
                </div>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h3>Follower Alert</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the new follower.<br><strong>Example:</strong> <code>Thank you (user) for following!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> - see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Bits &amp; Cheers</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the person who cheered bits.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(bits)</code></div>
            <div class="sp-card-body">The number of bits cheered in this event.<br><strong>Example:</strong> <code>Thank you for (bits) bits!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(total-bits)</code></div>
            <div class="sp-card-body">The total bits this user has given to the channel.<br><strong>Example:</strong> <code>You've given (total-bits) bits total!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> - see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Raid</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the raider.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(viewers)</code></div>
            <div class="sp-card-body">The number of viewers who joined with the raid.<br><strong>Example:</strong> <code>(user) raided with (viewers) viewers!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> - see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Hype Train</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(level)</code></div>
            <div class="sp-card-body">The current or final level of the hype train.<br><strong>Example:</strong> <code>Hype train is at level (level)!</code></div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Standard Subscriptions</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the subscriber.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(tier)</code></div>
            <div class="sp-card-body">The subscription tier (Tier 1, Tier 2, or Tier 3). A Prime sub reports as Tier 1.<br><strong>Example:</strong> <code>You are now a (tier) subscriber!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(months)</code></div>
            <div class="sp-card-body">The cumulative number of months the user has been subscribed.<br><strong>Example:</strong> <code>Subscribed for (months) months!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> - see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Gift Subscriptions</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the gifter. Resolves to <code>Anonymous</code> if the gift was sent anonymously.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(tier)</code></div>
            <div class="sp-card-body">The subscription tier being gifted (Tier 1, Tier 2, or Tier 3).<br><strong>Example:</strong> <code>Thank you (user) for gifting a (tier) subscription!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(count)</code></div>
            <div class="sp-card-body">The number of gift subscriptions given in this event.<br><strong>Example:</strong> <code>Gifted (count) subscriptions!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(total-gifted)</code></div>
            <div class="sp-card-body">The total gift subscriptions this user has given to the channel.<br><strong>Example:</strong> <code>You've gifted (total-gifted) subs total!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(gifter)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The username of the original gifter (for pay-it-forward events).<br><strong>Example:</strong> <code>Thank you (user) for paying it forward! They received a gift from (gifter).</code></div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Subscription Upgrade <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(user)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The username of the person who upgraded their subscription.<br><strong>Example:</strong> <code>Thank you (user) for upgrading to a paid subscription!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(tier)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The tier they upgraded to (Tier 1, Tier 2, or Tier 3).<br><strong>Example:</strong> <code>Thank you for upgrading to a (tier) subscription!</code></div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Watch Streak <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(user)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The display name of the viewer whose watch streak updated.<br><strong>Example:</strong> <code>Congrats (user) on watching (value) consecutive streams!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(value)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The viewer's current consecutive-stream watch streak.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(total)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The viewer's cumulative total streams watched. Only included when it's higher than the current streak.<br><strong>Example:</strong> <code>They've watched a total of (total) streams.</code></div>
        </div>
    </div>
`,"twitch-channel-points":`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Twitch Channel Points</h1>
            <p style="margin:0;color:var(--text-secondary);">Sync rewards and automate redemption responses with BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="twitch-channel-points" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What are Twitch Channel Points?</h2>
    <p>Twitch Channel Points are a loyalty system that allows streamers to reward their viewers for watching, following, subscribing, and participating in the stream. Viewers earn points over time and can redeem them for various rewards that you create.</p>
    <p>BotOfTheSpecter integrates seamlessly with Twitch's Channel Points system, allowing you to automate responses and create custom experiences when viewers redeem rewards.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            Channel Points are managed through Twitch's dashboard and are available for <strong>Affiliate/Partner</strong> channels. BotOfTheSpecter enhances this system by syncing your rewards and automating responses when redemptions happen.
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Setting Up &amp; Syncing Rewards</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-gear"></i> Setting Up Channel Points on Twitch</div>
            <div class="sp-card-body">
                <ol style="margin:0;padding-left:1.25rem;">
                    <li>Go to your <a href="https://dashboard.twitch.tv/" target="_blank" rel="noopener">Twitch Dashboard</a>.</li>
                    <li>Navigate to the <strong>Channel Points</strong> section.</li>
                    <li>Create custom rewards with titles, costs, and descriptions.</li>
                    <li>Enable the rewards you want to use (Affiliate/Partner required).</li>
                    <li>Use BotOfTheSpecter to sync and customize responses.</li>
                </ol>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-arrows-rotate"></i> Syncing Rewards in Specter</div>
            <div class="sp-card-body">
                <p>To use Channel Points with BotOfTheSpecter, sync your rewards from Twitch. This updates reward IDs, titles, and costs so the bot can recognise redemptions and trigger your configured actions.</p>
                <ol style="margin:0.75rem 0 0;padding-left:1.25rem;">
                    <li>Log into your BotOfTheSpecter dashboard.</li>
                    <li>Go to the <strong>Channel Rewards</strong> page.</li>
                    <li>Click the <strong>Sync Rewards</strong> button.</li>
                    <li>Wait for the sync to complete.</li>
                    <li>Your rewards will appear in the table.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>Sync your rewards whenever you add, modify, or remove rewards on Twitch to keep everything up to date.</div>
    </div>

    <hr class="sp-divider">

    <h2>Customizing Reward Responses</h2>
    <p>Once your rewards are synced, you can customize the bot's response for each redemption. This allows you to create personalized experiences for your viewers.</p>
    <h3>How to Customize</h3>
    <ol>
        <li>Find the reward in the Channel Rewards table.</li>
        <li>Click the <strong>Edit</strong> button next to the reward.</li>
        <li>Enter your custom message in the text area (up to 255 characters).</li>
        <li>Click <strong>Save</strong> to apply the changes.</li>
    </ol>
    <h3>Message Variables</h3>
    <p>You can use the following variables in your custom reward messages. For the full shared-variable list, see the <a href="#" data-goto="variables">Variables</a> guide.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.75rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code>(user)</code></div>
            <div class="sp-card-body">Tags the user who redeemed the reward.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(usercount)</code></div>
            <div class="sp-card-body">Shows how many times the user has redeemed the reward.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(userstreak)</code></div>
            <div class="sp-card-body">Shows how many times <em>in a row</em> the user has redeemed the reward.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(track)</code></div>
            <div class="sp-card-body">Increments internal reward usage tracking. Does <strong>not</strong> post any text to chat.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(tts)</code></div>
            <div class="sp-card-body">Sends the redemption user input to TTS (if present). See also the <a href="#" data-goto="tts">TTS guide</a>.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(tts.message)</code></div>
            <div class="sp-card-body">Sends your final custom message to both chat and TTS.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(lotto)</code></div>
            <div class="sp-card-body">Generates the user's lotto numbers.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(fortune)</code></div>
            <div class="sp-card-body">Inserts a random fortune response.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(vip)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">Attempts to grant the redeemer VIP status via Twitch.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(vip.today)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">Grants temporary VIP intended for current stream use.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(customapi.URL)</code></div>
            <div class="sp-card-body">Fetches data from a custom API endpoint and prints the raw response.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(customapi.json.URL)</code> + <code style="color:#c813e0;">(json.*)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">Fetches JSON silently and inserts a specific field from the response.</div>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <code>(fortune)</code>, <code>(lotto)</code>, and <code>(tts)</code> are variable-based triggers. You can place them in <strong>any</strong> reward message instead of relying on a specific reward title.
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Best Practices</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-star"></i> Reward Design</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Set appropriate point costs based on value</li>
                    <li>Use clear, descriptive titles</li>
                    <li>Include cooldowns for high-value rewards</li>
                    <li>Limit redemptions per stream/user if needed</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-users"></i> Engagement Tips</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Announce rewards during stream</li>
                    <li>Create themed reward sets</li>
                    <li>Rotate rewards to keep things fresh</li>
                    <li>Monitor redemption patterns</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-robot"></i> Bot Integration</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Keep custom messages fun and engaging</li>
                    <li>Use variables to personalise responses</li>
                    <li>Use the Manage option to convert rewards to Specter-managed when needed</li>
                    <li>Map rewards to sounds/videos for overlay alerts if needed</li>
                    <li>Test rewards before going live</li>
                    <li>Regularly sync rewards from Twitch</li>
                </ul>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-triangle-exclamation"></i> Rewards not appearing after sync</div>
            <div class="sp-card-body">Make sure the rewards are <strong>enabled</strong> on Twitch and try syncing again from the dashboard.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-comment-slash"></i> Custom messages not working</div>
            <div class="sp-card-body">
                <p>Ensure you've saved the custom message and that the bot has mod permissions on your channel.</p>
                <p style="margin-top:0.5rem;">If you use <code>(customapi.json...)</code>, make sure your <code>(json.path.to.value)</code> matches the API response structure.</p>
                <p style="margin-top:0.5rem;">Check the bot's logs for errors and report them on GitHub or Discord.</p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-robot"></i> Redemptions not triggering responses</div>
            <div class="sp-card-body">Verify that the reward is synced, your channel is Affiliate/Partner, and the bot is running. Make sure the correct reward was redeemed and your response is configured for that reward ID in Specter.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-clock-rotate-left"></i> Redemption history is empty</div>
            <div class="sp-card-body">Recent redemption history only loads for <strong>Specter-managed</strong> rewards. If a reward is Twitch-only, convert it using the <strong>Manage</strong> button in Channel Rewards first.</div>
        </div>
    </div>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Need more help?</strong> Check the <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" rel="noopener">GitHub Issues</a> or join our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord Server</a> for support.
        </div>
    </div>
`,api:`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Custom API Documentation</h1>
            <p style="margin:0;color:var(--text-secondary);">Programmatic access for integrations, overlays, and external tools.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="api" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>API Overview &amp; Authentication</h2>
    <p>The BotOfTheSpecter API enables programmatic access to various bot features, allowing developers to build custom integrations, extensions, and applications that interact with the bot's functionality.</p>
    <p>All authenticated API requests require your unique API key. This key is essential for BotOfTheSpecter integrations, including API access, WebSocket server connections, and third-party platform integrations.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>v2 Authentication Update:</strong> Authenticated <code>/v2/</code> endpoints support sending your key in the <code>X-API-KEY</code> request header. This is the recommended approach for better security. Legacy endpoints still support <code>?api_key=YOUR_API_KEY</code> where applicable.<br>
            Full v2 docs: <a href="https://api.botofthespecter.com/v2/docs" target="_blank" rel="noopener">https://api.botofthespecter.com/v2/docs</a>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Obtaining Your API Key</h3>
    <ol>
        <li>Log in to the <a href="https://dashboard.botofthespecter.com/" target="_blank" rel="noopener">BotOfTheSpecter Dashboard</a>.</li>
        <li>Navigate to <strong>Dashboard → Profile</strong>.</li>
        <li>Locate your API key in the <strong>API Access</strong> section of the Profile page.</li>
    </ol>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Keep your API key secure.</strong> Do not share it publicly or include it in client-side code. Your API key provides full access to your BotOfTheSpecter account.
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">API Key Regeneration</h3>
    <p>If you believe your API key has been compromised:</p>
    <ol>
        <li>Go to <strong>Dashboard → Profile</strong>.</li>
        <li>Click the regenerate button in the API Key section.</li>
        <li><strong>Important:</strong> Regenerating your key requires a full restart of all BotOfTheSpecter components (Twitch Chat Bot &amp; Overlays). Restart them via the dashboard after regenerating.</li>
    </ol>

    <hr class="sp-divider">

    <h2>Endpoint Quick Reference</h2>
    <p>BotOfTheSpecter's API provides several endpoint groups. Some are public; others require your user API key. For <code>/v2/</code> endpoints, prefer the <code>X-API-KEY</code> header.</p>
    <div class="sp-card" style="margin-top:1rem;">
        <div class="sp-card-header">Authenticated Endpoint Highlights (v2)</div>
        <div class="sp-card-body">
            <div style="display:flex;flex-wrap:wrap;gap:0.4rem;">
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/account</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/bot/status</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/checkkey</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/streamonline</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/quotes</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/fortune</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/kill</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/joke</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/weather</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/sound-alerts</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/custom-commands</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/user-points</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-points/credit</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-points/debit</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/user-commands/get</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-commands/add</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-commands/remove</span>
                <span style="width:100%;margin:16px 0 8px 0;font-size:0.95rem;color:#0d6efd;font-weight:700;">
                    EVENTS &amp; WebSocket Triggers
                </span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/tts</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/walkon</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/deaths</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/sound_alert</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/custom_command</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/stream_online</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/stream_offline</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/raffle_winner</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">POST /v2/SEND_OBS_EVENT</span>
                <span style="width:100%;margin:16px 0 8px 0;font-size:0.95rem;color:#888;font-weight:700;">
                    Webhooks
                </span>
                <span class="sp-badge" style="background:#6c757d;color:white;">POST /patreon</span>
                <span class="sp-badge" style="background:#6c757d;color:white;">POST /kofi</span>
                <span class="sp-badge" style="background:#6c757d;color:white;">POST /fourthwall</span>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Endpoint Reference: Public &amp; Commands</h2>

    <h3>Public (no authentication required)</h3>
    <ul>
        <li><code>GET /freestuff/games</code> - Get recent free games</li>
        <li><code>GET /freestuff/latest</code> - Get the most recent free game</li>
        <li><code>GET /versions</code> - Get the current bot versions</li>
        <li><code>GET /commands/info</code> - Get builtin commands information</li>
        <li><code>GET /heartbeat/websocket</code> - Get the heartbeat status of the websocket server</li>
        <li><code>GET /heartbeat/api</code> - Get the heartbeat status of the API server</li>
        <li><code>GET /heartbeat/database</code> - Get the heartbeat status of the database server</li>
        <li><code>GET /system/uptime</code> - Get API process uptime</li>
        <li><code>GET /chat-instructions</code> - Get AI chat instructions</li>
        <li><code>GET /api/song</code> - Get the remaining song requests</li>
        <li><code>GET /api/exchangerate</code> - Get the remaining exchangerate requests</li>
        <li><code>GET /api/weather</code> - Get the remaining weather API requests</li>
        <li><code>GET /api/steamapplist</code> - Get Steam app list mapping</li>
    </ul>

    <h3>Webhooks (require API key)</h3>
    <ul>
        <li><code>POST /fourthwall</code> - Receive and process FOURTHWALL Webhook Requests</li>
        <li><code>POST /kofi</code> - Receive and process KOFI Webhook Requests</li>
        <li><code>POST /patreon</code> - Receive and process Patreon Webhook Requests</li>
    </ul>

    <h3>Commands (requires user API key)</h3>
    <ul>
        <li><code>GET /v2/quotes</code> - Get a random quote</li>
        <li><code>GET /v2/fortune</code> - Get a random fortune</li>
        <li><code>GET /v2/kill</code> - Retrieve the Kill Command Responses</li>
        <li><code>GET /v2/joke</code> - Get a random joke</li>
        <li><code>GET /v2/sound-alerts</code> - Get list of sound alerts for user</li>
        <li><code>GET /v2/custom-commands</code> - Get list of custom commands for your account</li>
        <li><code>GET /v2/user-commands/get</code> - Get list of user managed commands</li>
        <li><code>POST /v2/user-commands/add</code> - Add a user managed command</li>
        <li><code>POST /v2/user-commands/remove</code> - Remove a user managed command</li>
        <li><code>GET /v2/weather</code> - Get weather data and trigger WebSocket weather event</li>
        <li><code>GET /v2/user-points</code> - Get user points</li>
        <li><code>POST /v2/user-points/credit</code> - Credit points to a user</li>
        <li><code>POST /v2/user-points/debit</code> - Debit points from a user</li>
    </ul>

    <h3>User Account (requires user API key)</h3>
    <ul>
        <li><code>GET /v2/account</code> - Get account information</li>
        <li><code>GET /v2/checkkey</code> - Check if the API key is valid</li>
        <li><code>GET /v2/streamonline</code> - Check if the stream is online</li>
        <li><code>POST /v2/discord/twitch-link/confirm</code> - Confirm Discord to Twitch link using one-time token</li>
        <li><code>GET /v2/bot/status</code> - Get chat bot status</li>
    </ul>

    <h3>WebSocket Triggers (requires user API key)</h3>
    <p>Endpoints that trigger real-time events via WebSocket to the bot and overlays.</p>
    <ul>
        <li><code>GET /v2/websocket/tts</code> - Trigger TTS via API</li>
        <li><code>GET /v2/websocket/walkon</code> - Trigger Walkon via API</li>
        <li><code>GET /v2/websocket/deaths</code> - Trigger Deaths via API</li>
        <li><code>GET /v2/websocket/sound_alert</code> - Trigger Sound Alert via API</li>
        <li><code>GET /v2/websocket/custom_command</code> - Trigger Custom Command via API</li>
        <li><code>GET /v2/websocket/stream_online</code> - Trigger Stream Online via API</li>
        <li><code>GET /v2/websocket/raffle_winner</code> - Trigger Raffle Winner via API</li>
        <li><code>GET /v2/websocket/stream_offline</code> - Trigger Stream Offline via API</li>
        <li><code>POST /v2/SEND_OBS_EVENT</code> - Pass OBS events to the websocket server</li>
    </ul>

    <hr class="sp-divider">

    <h2>Using the API</h2>
    <p>For <code>/v2/</code> endpoints, send your API key in the <code>X-API-KEY</code> header. Legacy endpoints can still use a URL query parameter where supported.</p>
    <p>Do not expose the key in public client-side code - treat it like a secret and rotate it if you suspect compromise.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Recommended for /v2/:</strong> <code>X-API-KEY: YOUR_API_KEY</code>
        </div>
    </div>

    <div class="sp-card" style="margin-top:1rem;" id="api-code-examples">
        <div class="sp-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <span>Code Examples</span>
            <label style="font-weight:normal;font-size:0.875rem;display:flex;align-items:center;gap:0.4rem;">
                Language:
                <select id="apiExampleLang" class="sp-select" style="min-width:10rem;">
                    <option value="curl">curl</option>
                    <option value="javascript">JavaScript (fetch)</option>
                    <option value="python">Python (requests)</option>
                    <option value="php">PHP (curl)</option>
                    <option value="java">Java (HttpClient)</option>
                </select>
            </label>
        </div>
        <div class="sp-card-body">
            <pre class="api-sample" data-lang="curl" style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>curl -H "X-API-KEY: YOUR_API_KEY" "https://api.botofthespecter.com/v2/account"</code></pre>
            <pre class="api-sample" data-lang="javascript" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>fetch('https://api.botofthespecter.com/v2/account', {
  headers: {
    'X-API-KEY': 'YOUR_API_KEY'
  }
})
.then(r =&gt; r.json())
.then(console.log);</code></pre>
            <pre class="api-sample" data-lang="python" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>import requests

resp = requests.get(
    'https://api.botofthespecter.com/v2/account',
    headers={'X-API-KEY': 'YOUR_API_KEY'}
)
print(resp.json())</code></pre>
            <pre class="api-sample" data-lang="php" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.botofthespecter.com/v2/account');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: YOUR_API_KEY']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
echo $response;</code></pre>
            <pre class="api-sample" data-lang="java" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>// Java 11+ HttpClient
HttpClient client = HttpClient.newHttpClient();
HttpRequest request = HttpRequest.newBuilder()
    .uri(URI.create("https://api.botofthespecter.com/v2/account"))
    .header("X-API-KEY", "YOUR_API_KEY")
    .GET()
    .build();
HttpResponse&lt;String&gt; resp = client.send(request, HttpResponse.BodyHandlers.ofString());
System.out.println(resp.body());</code></pre>
            <p style="margin-top:0.75rem;font-size:0.9rem;margin-bottom:0;">Replace <code>YOUR_API_KEY</code> with the key from your dashboard. For <code>/v2/</code> routes, always send it via the <code>X-API-KEY</code> header and avoid passing keys in URLs.</p>
        </div>
    </div>

    <div class="sp-alert sp-alert-info" style="margin-top:1.25rem;">
        <i class="fa-solid fa-book"></i>
        <div>
            Interactive API explorer:
            <a href="https://api.botofthespecter.com/docs" target="_blank" rel="noopener">api.botofthespecter.com/docs</a>
            ·
            <a href="https://api.botofthespecter.com/v2/docs" target="_blank" rel="noopener">v2 docs</a>
        </div>
    </div>
`,"run-yourself":`<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Run BotOfTheSpecter Yourself</h1>
            <p style="margin:0;color:var(--text-secondary);">Self-host SpecterSystems on your own Linux servers.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="run-yourself" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What is Self-Hosting?</h2>
    <div class="sp-alert sp-alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Complete Freedom &amp; Control</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">To run the source code of BotOfTheSpecter on your own set of servers and not use our hosted system, you'll have complete freedom to host it yourself with more control over your data. BotOfTheSpecter runs on a full headless Linux server architecture.</p>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Advanced Setup Required</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">Running SpecterSystems yourself requires technical knowledge of server administration, Python, PHP, and Linux. This is recommended for experienced developers and system administrators only.</p>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Self-Hosting Note</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">If you're interested in running BotOfTheSpecter on your own servers, please be aware that the self-hosting documentation may not always reflect the latest changes. Self-hosting is recommended for experienced developers who are comfortable troubleshooting issues independently. While we're happy to help with our hosted service, our support team focuses primarily on the cloud-hosted version and may not be able to assist with self-hosting setup or issues.</p>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Server Architecture</h2>
    <p>The minimum setup required to run SpecterSystems consists of <strong>4 servers</strong> running on a headless Linux architecture. A 5-server setup is recommended for production deployments.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1rem;">
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #3273dc;">Server 1: Web / Dashboard</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 1+ core</li>
                    <li><strong>RAM:</strong> 1 GB minimum</li>
                    <li><strong>Service:</strong> PHP / Caddy Dashboard</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #48c774;">Server 2: API</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 1+ core</li>
                    <li><strong>RAM:</strong> 1 GB minimum</li>
                    <li><strong>Service:</strong> FastAPI server</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #ffdd57;">Server 3: WebSocket</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 1+ core</li>
                    <li><strong>RAM:</strong> 1 GB minimum</li>
                    <li><strong>Service:</strong> Python SocketIO server</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #f14668;">Server 4: Database</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 2+ cores</li>
                    <li><strong>RAM:</strong> 4 GB minimum</li>
                    <li><strong>Service:</strong> MySQL</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="sp-card" style="margin-top:1rem;">
        <div class="sp-card-header" style="border-left:4px solid #b56edb;">
            Server 5: Bot <span class="sp-badge" style="background:#b56edb;color:#fff;margin-left:0.5rem;">Recommended</span>
        </div>
        <div class="sp-card-body">
            <p>For production with improved reliability and scalability. This is how SpecterSystems currently runs.</p>
            <ul style="margin:0.5rem 0 0;padding-left:1.25rem;">
                <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                <li><strong>CPU:</strong> 2+ cores</li>
                <li><strong>RAM:</strong> 4 GB minimum</li>
                <li><strong>Service:</strong> Python bot process</li>
            </ul>
            <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;font-size:0.9rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    The 2+ cores / 4 GB RAM spec is for running many bots for multiple users. If you're only running a <strong>single bot</strong> for personal use, 1 core and 1 GB RAM is sufficient.
                </div>
            </div>
            <div class="sp-alert sp-alert-warning" style="margin-top:0.75rem;font-size:0.9rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Running multiple bots on one server:</strong> budget roughly <strong>150-200 MB of RAM per additional bot</strong> on top of the base 1 GB - memory use is cumulative across every bot running on that host. A server sized for "many bots, many users" needs meaningfully more RAM than the minimum above; undersizing it risks the Linux OOM killer terminating bot processes outright (including ones unrelated to whichever bot triggered the spike) instead of a clean, contained failure. Configuring a swap file is strongly recommended as a safety margin against memory spikes, even if you don't expect to hit these numbers day-to-day.
                </div>
            </div>
        </div>
    </div>
    <h3 style="margin-top:1.25rem;">Common Software Requirements (All Servers)</h3>
    <ul>
        <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS or newer)</li>
        <li><strong>Python:</strong> 3.8+ (Bot, API, and WebSocket servers)</li>
        <li><strong>PHP:</strong> 8.0+ (Web/Dashboard server)</li>
        <li><strong>Caddy</strong> (Web/Dashboard server)</li>
        <li><strong>MySQL</strong> (Database server)</li>
        <li><strong>Git:</strong> For version control</li>
    </ul>
    <h3>Network &amp; Services</h3>
    <ul>
        <li>Twitch API credentials (OAuth tokens)</li>
        <li>Discord bot token <em>(optional)</em></li>
        <li>Spotify API credentials <em>(optional)</em></li>
        <li>OpenWeatherMap API key <em>(optional)</em></li>
        <li>SSL/TLS certificates for secure communication</li>
        <li>Firewall configured for internal communication</li>
    </ul>

    <hr class="sp-divider">

    <h2>Recommended Hosting: Linode</h2>
    <div class="sp-alert sp-alert-info">
        <i class="fa-solid fa-cloud"></i>
        <div>
            <strong>We recommend running SpecterSystems on Linode.</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">Our systems have been fully tested and optimized to work seamlessly on Linode's infrastructure.</p>
        </div>
    </div>
    <p style="margin-top:1rem;"><strong>Get $100 in free credit:</strong> Use our referral link to receive <strong>$100 of Linode credit</strong> to use within 60 days once you've entered a valid payment method to your Linode account.</p>
    <p style="margin-top:1rem;">
        <a href="https://www.linode.com/lp/refer/?r=210010495bf7dc151d31289c7bc399f8933f79e3" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Get $100 Linode Credit
        </a>
    </p>

    <hr class="sp-divider">

    <h2>Self-host outline</h2>
    <p>Advanced users can run a private copy from the public source. The hosted production layout is <strong>not</strong> documented here. Use your own service user, install prefix (for example <code>/opt/specter</code>), and domain names.</p>
    <ol>
        <li>Clone <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener">the GitHub repository</a>.</li>
        <li>Split the web frontend, API, realtime hub, chat bot, and database onto one machine or several — whichever you can operate.</li>
        <li>Create a dedicated, <strong>non-sudo</strong> service account. Do not copy production usernames or home directories.</li>
        <li>Point each process at a local virtualenv and its own requirements file from the repo.</li>
    </ol>

    <h3 style="margin-top:1.5rem;">Configuration</h3>
    <p>Keep secrets in an environment file that is <strong>not</strong> committed and is readable only by the service account. A self-host typically needs:</p>
    <ul>
        <li>A database DSN (host, name, user, password) — prefer localhost unless you have a private network</li>
        <li>Your own Twitch application client ID and client secret</li>
        <li>A user API key you generate for that instance</li>
    </ul>
    <p>Optional integrations (weather, Spotify, Discord, object storage, email) use the credentials those providers issue to <em>you</em>. Do not reuse keys from the hosted service.</p>

    <h3 style="margin-top:1.5rem;">Database</h3>
    <p>The installer / first dashboard login creates the system database and each channel database. Do not publish or copy a schema dump, and do not grant a remote user all privileges.</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.75rem;"><code>CREATE USER 'specter'@'localhost' IDENTIFIED BY 'your-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON specter.* TO 'specter'@'localhost';
FLUSH PRIVILEGES;</code></pre>
    <p style="margin-top:0.75rem;">Leave MySQL listening on localhost unless you have a private network and know you need otherwise. Do not bind the database to the public internet.</p>

    <h3 style="margin-top:1.5rem;">Web frontend and TLS</h3>
    <p>Serve the dashboard and overlays over HTTPS on your own domain. Use any reverse proxy you already operate. Keep TLS certificates and DNS tokens in the proxy's private config, not in public documentation.</p>

    <h3 style="margin-top:1.5rem;">Starting processes</h3>
    <p>Run the API, realtime hub, and bot under your process manager. Point the start command at your prefix and virtualenv — there is no required production path. Start the database with the distro service manager. Start the chat bot from the dashboard once the API is up.</p>

    <h3 style="margin-top:1.5rem;">Networking</h3>
    <ul>
        <li>Expose only HTTPS (and HTTP for certificate issuance if you use it) to the public internet</li>
        <li>Keep the database on localhost or a private network</li>
        <li>Allow only the hosts that must talk to each other</li>
    </ul>

    <hr class="sp-divider">

    <h2>Security Considerations</h2>
    <ul>
        <li><strong>HTTPS/SSL:</strong> Always use SSL certificates for all services - Let's Encrypt is free</li>
        <li><strong>Firewall:</strong> Restrict database access to only the servers that need it</li>
        <li><strong>Environment Variables:</strong> Never commit <code>.env</code> files to version control</li>
        <li><strong>Database Backups:</strong> Set up automated daily backups</li>
        <li><strong>Updates:</strong> Keep dependencies updated to patch security vulnerabilities</li>
        <li><strong>Monitoring:</strong> Monitor system resources and bot logs for issues</li>
    </ul>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-brands fa-twitch"></i> Bot Not Connecting to Twitch</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify your OAuth token is valid and not expired</li>
                    <li>Check that your Twitch Client ID and Secret are correct</li>
                    <li>Ensure the bot account has the proper channel permissions</li>
                    <li>Review logs in <code>bot/logs/</code> for error messages</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-database"></i> Database Connection Errors</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify MySQL is running on Server 4</li>
                    <li>Check credentials in your <code>.env</code> file</li>
                    <li>Ensure the user has proper database permissions</li>
                    <li>Test: <code>mysql -u botuser -p -h &lt;db-host&gt;</code></li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-server"></i> API Server Not Responding</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify FastAPI/Uvicorn is running</li>
                    <li>Check that port 443 is not in use by another service</li>
                    <li>Review API logs for startup errors</li>
                    <li>Ensure all Python dependencies are installed</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-plug"></i> WebSocket Connection Issues</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify WebSocket server is running on port 443</li>
                    <li>Check firewall rules allow WebSocket connections</li>
                    <li>Ensure the WebSocket URL is correctly configured in clients</li>
                    <li>Review WebSocket server logs for errors</li>
                </ul>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Maintenance</h2>
    <h3>Regular Tasks</h3>
    <ul>
        <li><strong>Daily:</strong> Check logs for errors and unusual activity</li>
        <li><strong>Weekly:</strong> Verify all services are running and responsive</li>
        <li><strong>Monthly:</strong> Update dependencies and apply security patches</li>
        <li><strong>Quarterly:</strong> Review and optimize database performance</li>
    </ul>
    <h3>Updating BotOfTheSpecter</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>git pull origin main
pip install -r bot/requirements.txt --upgrade
pip install -r api/requirements.txt --upgrade</code></pre>

    <hr class="sp-divider">

    <h2>Need Help?</h2>
    <p>If you encounter issues while self-hosting BotOfTheSpecter:</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-brands fa-github"></i> GitHub Issues</div>
            <div class="sp-card-body">
                <p>Report bugs or browse existing issues on GitHub.</p>
                <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" rel="noopener" class="sp-btn sp-btn-secondary sp-btn-sm">Open GitHub Issues</a>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-brands fa-discord"></i> Discord Community</div>
            <div class="sp-card-body">
                <p>Join our community for help and discussion.</p>
                <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener" class="sp-btn sp-btn-secondary sp-btn-sm">Join Discord</a>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-ticket"></i> Support Ticket</div>
            <div class="sp-card-body">
                <p>Open a ticket if you need direct assistance.</p>
                <a href="/tickets.php?action=new" class="sp-btn sp-btn-primary sp-btn-sm">Open a Ticket</a>
            </div>
        </div>
    </div>
`,faq:`<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Frequently Asked Questions</h1>
            <p style="margin:0;color:var(--text-secondary);">Common questions about BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="faq" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up the bot for the first time?</div>
        <div class="sp-faq-a">Follow the <a href="#" data-goto="setup">First Time Setup</a> guide - connect Twitch, mod the bot, start it from the dashboard, then configure points and custom commands.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">What main features does the bot include?</div>
        <div class="sp-faq-a">See the <a href="#" data-goto="features">Main Features</a> guide for chat protection, custom commands, games, events, tracking, and integrations.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up Spotify with the bot?</div>
        <div class="sp-faq-a">New users need their own Spotify Developer app (platform client is capped). Follow the <a href="#" data-goto="spotify">Spotify Setup</a> guide to create an app, enter credentials, and link your account.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up Text-to-Speech (TTS)?</div>
        <div class="sp-faq-a">Go to <strong>Modules → TTS Settings</strong>, pick Normal or Expressive and a voice, add your audio overlay in OBS with monitoring enabled, then trigger TTS via Channel Points or <code>(tts)</code>. Full voice samples and laugh-word list are in the <a href="#" data-goto="tts">Text-to-Speech</a> guide.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">What built-in commands are there for the bot?</div>
        <div class="sp-faq-a">BotOfTheSpecter comes with many built-in commands for moderation, entertainment, and utility. See the <a href="#" data-goto="commands">Command Reference</a> tab for the full list.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up audio monitoring in OBS?</div>
        <div class="sp-faq-a">Follow the step-by-step <a href="#" data-goto="obs-audio">OBS Audio Monitoring</a> guide: set your monitoring device, add the Specter overlay browser source with <em>Control audio via OBS</em>, then set Audio Monitoring to <strong>Monitor and Output</strong>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">I'm having trouble with the bot. What should I do?</div>
        <div class="sp-faq-a">Start with the <a href="#" data-goto="troubleshooting">Troubleshooting</a> tab which covers the most common problems. If you're still stuck, <a href="/tickets.php?action=new">submit a support ticket</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I use custom variables in commands?</div>
        <div class="sp-faq-a">Custom commands, timed messages, and channel point rewards support dynamic variables like <code>(user)</code>, <code>(count)</code>, and <code>(customapi.URL)</code>. See the full list in the <a href="#" data-goto="variables">Variables</a> guide.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">What variables work in welcome messages and event alerts?</div>
        <div class="sp-faq-a">Event alerts use event-specific tokens (bits, raids, subs, ad notices, etc.) plus every universal variable. See the <strong>Event Alert Variables</strong> section of the <a href="#" data-goto="variables">Variables</a> guide.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do Twitch Channel Points work with the bot?</div>
        <div class="sp-faq-a">Sync rewards from the dashboard Channel Rewards page, then set custom redemption messages with variables. Full walkthrough: <a href="#" data-goto="twitch-channel-points">Channel Points</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I use the BotOfTheSpecter API?</div>
        <div class="sp-faq-a">Get your API key from Dashboard → Profile, send it as <code>X-API-KEY</code> on <code>/v2/</code> routes, and see the <a href="#" data-goto="api">Custom API</a> guide for endpoints and code samples. Full API explorer: <a href="https://api.botofthespecter.com/v2/docs" target="_blank" rel="noopener">api.botofthespecter.com/v2/docs</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Can I self-host BotOfTheSpecter?</div>
        <div class="sp-faq-a">Yes - advanced users can run Specter on their own Linux servers. See the <a href="#" data-goto="run-yourself">Run Yourself</a> guide. Support focuses on the hosted service; self-hosting requires independent troubleshooting.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Can I request a new built-in command?</div>
        <div class="sp-faq-a">Yes! We're always looking for new commands to add. Let us know on our dev streams, via <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord</a>, or email <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Where can I get more help?</div>
        <div class="sp-faq-a">Join our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord server</a>, watch the developer stream at <a href="https://twitch.tv/gfaundead" target="_blank" rel="noopener">twitch.tv/gfaundead</a>, or <a href="/tickets.php?action=new">submit a support ticket</a>.</div>
    </div>
`,troubleshooting:`<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Troubleshooting Guide</h1>
            <p style="margin:0;color:var(--text-secondary);">Common issues and solutions for BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="troubleshooting" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Bot Not Connecting</h2>
    <p>If your bot isn't connecting to Twitch:</p>
    <ul>
        <li>Try stopping and starting the bot from the dashboard under <strong>Bot Control</strong>.</li>
    </ul>

    <h2>Commands Not Working</h2>
    <p>If commands aren't responding:</p>
    <ul>
        <li>Check that the command is enabled in the dashboard, or use <code>!enablecommand commandname</code> in chat.</li>
        <li>Commands always use the <code>!</code> prefix - verify the bot has <strong>Moderator</strong> permissions in your channel.</li>
        <li>Double-check the spelling of the command name both in chat and in the dashboard.</li>
    </ul>

    <h2>Sound Alerts / TTS / Walk-ons - Audio Issues</h2>
    <p>All Specter audio goes through the audio overlays. Make sure you're running the correct overlay, or use the <strong>All Audio</strong> overlay:</p>
    <p><code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code></p>
    <ul>
        <li>Check audio device settings in OBS.</li>
        <li>Ensure the OBS Browser Source volume is audible and <a href="#" data-goto="obs-audio">audio monitoring is configured correctly</a>.</li>
        <li>If you hear an echo, set Audio Monitoring to <strong>Monitor Only (mute output)</strong> and test again - everyone's audio setup differs.</li>
        <li>Confirm you've entered the correct API key, found on your <strong>Specter Profile</strong> page in the dashboard.</li>
        <li>For voice or style issues, see the <a href="#" data-goto="tts">Text-to-Speech</a> guide (Normal vs Expressive, laugh words, and samples).</li>
    </ul>

    <h2>Still Stuck?</h2>
    <p>If none of the above resolves your issue, <a href="/tickets.php?action=new">submit a support ticket</a> and include:</p>
    <ul>
        <li>A description of what you expected to happen vs. what actually happened.</li>
        <li>Any error messages you see (screenshots are helpful).</li>
        <li>Your Twitch username and approximate time the issue occurred.</li>
    </ul>
    <div class="sp-alert sp-alert-info sp-mt-2">
        <i class="fa-solid fa-circle-info"></i>
        <span>You can also check the <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" rel="noopener">GitHub Issues</a> page or join our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord server</a> for community support.</span>
    </div>
`},S=e((e=>{var t=Symbol.for(`react.transitional.element`),n=Symbol.for(`react.fragment`);function r(e,n,r){var i=null;if(r!==void 0&&(i=``+r),n.key!==void 0&&(i=``+n.key),`key`in n)for(var a in r={},n)a!==`key`&&(r[a]=n[a]);else r=n;return n=r.ref,{$$typeof:t,type:e,key:i,ref:n===void 0?null:n,props:r}}e.Fragment=n,e.jsx=r,e.jsxs=r})),C=e(((e,t)=>{t.exports=S()}))(),w=[{id:`setup`,icon:`fa-solid fa-rocket`,title:`First Time Setup`,desc:`Get the bot running on your channel.`},{id:`features`,icon:`fa-solid fa-star`,title:`Main Features`,desc:`Commands, games, events, tracking, and integrations.`},{id:`spotify`,icon:`fa-brands fa-spotify`,title:`Spotify Setup`,desc:`Create your own Spotify app and link it.`},{id:`tts`,icon:`fa-solid fa-microphone`,title:`Text-to-Speech`,desc:`Normal and Expressive voices, Channel Points TTS, and setup tips.`},{id:`obs-audio`,icon:`fa-solid fa-headphones`,title:`OBS Audio Monitoring`,desc:`Hear overlay alerts, TTS, and walk-ons in OBS.`},{id:`variables`,icon:`fa-solid fa-code`,title:`Variables`,desc:`Dynamic tokens for commands, timers, rewards, and event alerts.`},{id:`twitch-channel-points`,icon:`fa-brands fa-twitch`,title:`Channel Points`,desc:`Sync rewards and automate redemption responses.`},{id:`api`,icon:`fa-solid fa-satellite-dish`,title:`Custom API`,desc:`Auth, endpoints, and code samples for integrations.`},{id:`run-yourself`,icon:`fa-solid fa-server`,title:`Run Yourself`,desc:`Self-host Specter on your own servers.`},{id:`commands`,icon:`fa-solid fa-terminal`,title:`Command Reference`,desc:`All built-in bot commands.`},{id:`faq`,icon:`fa-solid fa-circle-question`,title:`FAQ`,desc:`Frequently asked questions.`},{id:`troubleshooting`,icon:`fa-solid fa-wrench`,title:`Troubleshooting`,desc:`Common issues and solutions.`}],ee=[...Object.keys(x),`commands`];function te(){return window.location.hash.replace(`#`,``)}function ne(e){let t=`#`+e;window.location.hash!==t&&history.replaceState(null,``,t);try{sessionStorage.setItem(`sp_active_tab`,e)}catch{}}function T({loggedIn:e}){let[t,n]=(0,l.useState)(()=>{let e=te();if(ee.includes(e))return e;try{let e=sessionStorage.getItem(`sp_active_tab`)||``;if(ee.includes(e))return e}catch{}return`setup`}),[r,i]=(0,l.useState)(``),[a,o]=(0,l.useState)(null),s=(0,l.useRef)(null);function c(e,t=!1){ee.includes(e)&&(n(e),ne(e),t&&requestAnimationFrame(()=>{(s.current?.querySelector(`.sp-tab-panel.active`))?.scrollIntoView({behavior:`smooth`,block:`start`})}))}(0,l.useEffect)(()=>{let e=()=>{let e=te();ee.includes(e)&&n(e)};return window.addEventListener(`hashchange`,e),te()?e():ne(t),()=>window.removeEventListener(`hashchange`,e)},[]),(0,l.useEffect)(()=>{p(`/api/commands.php`).then(e=>{o(e?.commands||{})}).catch(()=>o({}))},[]),(0,l.useEffect)(()=>{p(`/api/tts-voices.php`).then(e=>{let t=document.getElementById(`react-tts-normal`);t&&e?.normal&&(t.innerHTML=Object.entries(e.normal).map(([e,t])=>`<div class="sp-card">
            <div class="sp-card-header">${e.charAt(0).toUpperCase()+e.slice(1)}</div>
            <div class="sp-card-body">
              <p style="color:var(--text-secondary);font-size:0.9rem;">${t}</p>
              <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm voice-play-button" style="margin-top:0.75rem;" data-voice="${e}">
                <i class="fa-solid fa-play"></i> Play Sample
              </button>
              <audio id="audio-${e}" preload="none" style="display:none;">
                <source src="https://cdn.botofthespecter.com/help/tts/${e}_sample.mp3" type="audio/mpeg">
                <source src="https://cdn.botofthespecter.com/help/tts/${e}_sample.wav" type="audio/wav">
              </audio>
            </div>
          </div>`).join(``));let n=document.getElementById(`react-tts-expressive`);if(n){let t=Array.isArray(e?.expressive)?e.expressive:[];n.innerHTML=t.length?`<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-top:1rem;">`+t.map(e=>{let t=String(e.name||e.slug||`Voice`),n=String(e.file||e.filename||``),r=String(e.slug||t).replace(/[^a-z0-9_-]/gi,``);return`<div class="sp-card">
                <div class="sp-card-header">${t}</div>
                <div class="sp-card-body">
                  <p style="color:var(--text-secondary);font-size:0.9rem;">Multilingual expressive voice</p>
                  <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm voice-play-button" style="margin-top:0.75rem;" data-voice="${r}">
                    <i class="fa-solid fa-play"></i> Play Sample
                  </button>
                  <audio id="audio-${r}" preload="none" style="display:none;">
                    <source src="https://cdn.botofthespecter.com/help/tts/expressive/${n}" type="audio/mpeg">
                  </audio>
                </div>
              </div>`}).join(``)+`</div>`:`<div class="sp-alert sp-alert-info" style="margin-top:1rem;">
            <i class="fa-solid fa-circle-info"></i>
            <div>Expressive voice samples will appear here once they are published to the CDN.</div>
          </div>`}re(document.querySelector(`.sp-tab-panel[data-panel="tts"]`))}).catch(()=>void 0)},[]),(0,l.useEffect)(()=>{let e=s.current;if(!e)return;function t(e){let t=e.target,n=t.closest(`[data-goto]`);if(n?.dataset.goto){e.preventDefault(),c(n.dataset.goto,!0);return}let r=t.closest(`.sp-copy-link`);if(r?.dataset.copyId){e.preventDefault();let t=window.location.origin+`/index.php#`+r.dataset.copyId,n=r.innerHTML,i=()=>{r.innerHTML=`<i class="fa-solid fa-check"></i>`,setTimeout(()=>{r.innerHTML=n},1500)};navigator.clipboard?.writeText?navigator.clipboard.writeText(t).then(i).catch(i):i();return}let i=t.closest(`.sp-faq-q`);if(i){let e=i.closest(`.sp-faq-item`);if(!e)return;let t=e.classList.contains(`open`);s.current?.querySelectorAll(`.sp-faq-item.open`).forEach(e=>e.classList.remove(`open`)),t||e.classList.add(`open`)}}return e.addEventListener(`click`,t),()=>e.removeEventListener(`click`,t)},[]);let u=(0,l.useMemo)(()=>{let e=r.trim().toLowerCase();if(e.length<2)return[];let t=[];for(let n of w){let r=n.id===`commands`?``:x[n.id]||``,i=document.createElement(`div`);i.innerHTML=r,i.querySelectorAll(`h2, h3, h4, .sp-faq-q`).forEach(r=>{let i=(r.textContent||``).trim();i.toLowerCase().includes(e)&&t.push({title:i,section:n.title,tab:n.id})})}return t.slice(0,8)},[r]),d=a?Object.entries(a):null;return(0,C.jsxs)(`div`,{ref:s,children:[(0,C.jsxs)(`div`,{className:`sp-hero`,style:{textAlign:`center`,padding:`1.5rem 1rem 1.25rem`,borderBottom:`1px solid var(--border)`,marginBottom:`1.5rem`},children:[(0,C.jsx)(`img`,{src:`https://cdn.botofthespecter.com/logo.png`,alt:`BotOfTheSpecter`,style:{width:72,height:72,borderRadius:`50%`,margin:`0 auto 1rem`,border:`2px solid var(--border)`,display:`block`}}),(0,C.jsx)(`h1`,{style:{fontSize:`1.75rem`,fontWeight:800,marginBottom:`0.5rem`},children:`BotOfTheSpecter Documentation`}),(0,C.jsx)(`p`,{style:{color:`var(--text-secondary)`,maxWidth:560,margin:`0 auto 1.5rem`},children:`Everything you need to set up, configure, and get the most from your streaming bot.`}),(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.75rem`,justifyContent:`center`,flexWrap:`wrap`},children:[(0,C.jsxs)(`a`,{href:`https://github.com/YourStreamingTools/BotOfTheSpecter`,target:`_blank`,rel:`noopener`,className:`sp-btn sp-btn-secondary`,children:[(0,C.jsx)(`i`,{className:`fa-brands fa-github`}),` View on GitHub`]}),e?(0,C.jsxs)(`a`,{href:`/tickets.php?action=new`,className:`sp-btn sp-btn-primary`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-ticket`}),` Submit a Support Ticket`]}):(0,C.jsxs)(`a`,{href:`/login.php`,className:`sp-btn sp-btn-primary`,children:[(0,C.jsx)(`i`,{className:`fa-brands fa-twitch`}),` Log in to Submit a Ticket`]})]})]}),(0,C.jsxs)(`div`,{className:`sp-search-wrap`,id:`sp-search-wrap`,style:{maxWidth:480,margin:`0 auto 1.5rem`,display:`block`,position:`relative`},children:[(0,C.jsx)(`i`,{className:`fa-solid fa-magnifying-glass sp-search-icon`}),(0,C.jsx)(`input`,{type:`text`,className:`sp-search-input`,placeholder:`Search docs…`,value:r,onChange:e=>i(e.target.value),autoComplete:`off`,spellCheck:!1,"aria-label":`Search documentation`}),r.trim().length>=2&&(0,C.jsx)(`div`,{id:`sp-search-results`,className:`open`,children:u.length===0?(0,C.jsxs)(`div`,{className:`sp-search-no-results`,children:[`No results for “`,r,`”`]}):u.map(e=>(0,C.jsxs)(`a`,{className:`sp-search-result-item`,href:`#`+e.tab,onClick:t=>{t.preventDefault(),i(``),c(e.tab,!0)},children:[(0,C.jsx)(`span`,{className:`sp-search-result-title`,children:e.title}),(0,C.jsx)(`span`,{className:`sp-search-result-section`,children:e.section})]},e.tab+e.title))})]}),(0,C.jsx)(`div`,{className:`sp-doc-grid sp-mb-3`,children:w.map(e=>(0,C.jsxs)(`a`,{href:`#`+e.id,className:`sp-doc-card`+(t===e.id?` active`:``),"data-goto":e.id,onClick:t=>{t.preventDefault(),c(e.id,!0)},children:[(0,C.jsx)(`div`,{className:`sp-doc-card-icon`,children:(0,C.jsx)(`i`,{className:e.icon})}),(0,C.jsx)(`div`,{className:`sp-doc-card-title`,children:e.title}),(0,C.jsx)(`div`,{className:`sp-doc-card-desc`,children:e.desc})]},e.id))}),Object.entries(x).map(([e,n])=>(0,C.jsx)(`div`,{className:`sp-tab-panel sp-doc-content`+(t===e?` active`:``),"data-panel":e,dangerouslySetInnerHTML:{__html:n}},e)),(0,C.jsxs)(`div`,{className:`sp-tab-panel sp-doc-content`+(t===`commands`?` active`:``),"data-panel":`commands`,children:[(0,C.jsxs)(`div`,{style:{display:`flex`,justifyContent:`space-between`,alignItems:`flex-start`,flexWrap:`wrap`,gap:`1rem`,marginBottom:`1.5rem`},children:[(0,C.jsxs)(`div`,{children:[(0,C.jsx)(`h1`,{style:{margin:`0 0 0.25rem`},children:`Command Reference`}),(0,C.jsxs)(`p`,{style:{margin:0,color:`var(--text-secondary)`},children:[`All commands use the `,(0,C.jsx)(`code`,{children:`!`}),` prefix. Some require moderator or broadcaster permissions.`]})]}),(0,C.jsxs)(`button`,{type:`button`,className:`sp-btn sp-btn-ghost sp-btn-sm sp-copy-link`,"data-copy-id":`commands`,title:`Copy link to this section`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-link`}),` Copy link`]})]}),d===null?(0,C.jsx)(`p`,{style:{color:`var(--text-secondary)`},children:`Loading commands…`}):d.length===0?(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-warning`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-triangle-exclamation`}),(0,C.jsx)(`span`,{children:`Command list unavailable - could not reach the commands API.`})]}):(0,C.jsx)(`div`,{className:`sp-table-wrap`,children:(0,C.jsxs)(`table`,{className:`sp-table sp-table-no-hover`,children:[(0,C.jsx)(`thead`,{children:(0,C.jsxs)(`tr`,{children:[(0,C.jsx)(`th`,{style:{width:`18%`},children:`Command`}),(0,C.jsx)(`th`,{style:{width:`35%`},children:`Description`}),(0,C.jsx)(`th`,{children:`Syntax`})]})}),(0,C.jsx)(`tbody`,{children:d.map(([e,t])=>{let n=t.aliases||[],r=t.syntax,i=Array.isArray(r)?r:r?[r]:[],a=t.force_level===`mod`;return(0,C.jsxs)(`tr`,{children:[(0,C.jsxs)(`td`,{children:[(0,C.jsxs)(`div`,{style:{display:`flex`,alignItems:`center`,gap:`0.4rem`,flexWrap:`wrap`},children:[(0,C.jsxs)(`code`,{children:[`!`,e]}),a&&(0,C.jsx)(`span`,{className:`sp-badge sp-badge-muted`,title:`Requires moderator or broadcaster`,children:`Mod`})]}),n.length>0&&(0,C.jsx)(`div`,{style:{marginTop:`0.35rem`,display:`flex`,flexWrap:`wrap`,gap:`0.3rem`},children:n.map(e=>(0,C.jsxs)(`code`,{style:{fontSize:`0.78rem`,color:`var(--text-secondary)`},children:[`!`,e]},e))})]}),(0,C.jsx)(`td`,{children:t.description||`No description available`}),(0,C.jsx)(`td`,{children:i.length>0&&(0,C.jsx)(`div`,{className:`sp-cmd-examples`,children:i.map(e=>(0,C.jsx)(`span`,{className:`sp-cmd-example`,children:e},e))})})]},e)})})]})}),(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-info sp-mt-2`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-info`}),(0,C.jsxs)(`span`,{children:[`Type `,(0,C.jsx)(`code`,{children:`!commands`}),` in your Twitch chat to see all active commands, including custom ones.`]})]})]})]})}function re(e){if(!e)return;let t=null;e.querySelectorAll(`.voice-play-button[data-voice]`).forEach(n=>{let r=n;r.dataset.bound!==`1`&&(r.dataset.bound=`1`,r.addEventListener(`click`,()=>{let n=r.getAttribute(`data-voice`),i=document.getElementById(`audio-`+n);if(!(!i||!n)){if(t===n&&!i.paused){i.pause(),i.currentTime=0,r.innerHTML=`<i class="fa-solid fa-play"></i> Play Sample`,t=null;return}e.querySelectorAll(`audio`).forEach(e=>{e.pause(),e.currentTime=0}),e.querySelectorAll(`.voice-play-button`).forEach(e=>{e.innerHTML=`<i class="fa-solid fa-play"></i> Play Sample`}),i.play().catch(()=>{alert(`Could not play audio sample. The file may not be available.`)}),r.innerHTML=`<i class="fa-solid fa-stop"></i> Stop`,t=n,i.onended=()=>{r.innerHTML=`<i class="fa-solid fa-play"></i> Play Sample`,t=null}}}))})}function ie(e){return(0,C.jsx)(`span`,{className:`sp-badge sp-status-`+e,children:v[e]||e})}function ae(e){return(0,C.jsxs)(`span`,{className:`sp-badge sp-prio-`+e,children:[(0,C.jsx)(`i`,{className:`fa-solid `+({low:`fa-arrow-down`,normal:`fa-minus`,high:`fa-arrow-up`}[e]||`fa-minus`)}),` `,e.charAt(0).toUpperCase()+e.slice(1)]})}function oe({session:e}){let t=new URLSearchParams(window.location.search),n=t.get(`id`),r=t.get(`action`),i=t.get(`view`)===`queue`&&e.is_staff;return n?(0,C.jsx)(le,{ticketNumber:n,session:e}):r===`new`?(0,C.jsx)(ce,{session:e}):(0,C.jsx)(se,{queue:i,session:e})}function se({queue:e,session:t}){let n=new URLSearchParams(window.location.search),[r,i]=(0,l.useState)(n.get(`status`)||`all`),[a,o]=(0,l.useState)(n.get(`priority`)||`all`),[s,c]=(0,l.useState)(null),[u,d]=(0,l.useState)(``);(0,l.useEffect)(()=>{let t=new URLSearchParams;e&&t.set(`view`,`queue`),r!==`all`&&t.set(`status`,r),e&&a!==`all`&&t.set(`priority`,a),p(`/api/tickets.php?`+t.toString()).then(e=>{if(!e?.ok){d(e?.error||`Could not load tickets.`),c([]);return}c(e.tickets||[])}).catch(()=>{d(`Could not load tickets.`),c([])})},[e,r,a]);function f(t,n){i(t),o(n);let r=new URLSearchParams;e&&r.set(`view`,`queue`),t!==`all`&&r.set(`status`,t),e&&n!==`all`&&r.set(`priority`,n);let a=r.toString();history.replaceState(null,``,`/tickets.php`+(a?`?`+a:``))}return(0,C.jsxs)(C.Fragment,{children:[!t.is_registered&&(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-warning`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-triangle-exclamation`}),(0,C.jsxs)(`span`,{children:[`This support system is only for users of `,(0,C.jsx)(`strong`,{children:`BotOfTheSpecter`}),` the Twitch and Discord bot. You don't appear to have a BotOfTheSpecter account. Please `,(0,C.jsx)(`a`,{href:`https://botofthespecter.com`,target:`_blank`,rel:`noopener`,children:`sign up at botofthespecter.com`}),` before submitting a ticket.`]})]}),(0,C.jsxs)(`div`,{className:`sp-page-header`,children:[(0,C.jsxs)(`div`,{children:[(0,C.jsx)(`h1`,{children:e?(0,C.jsxs)(C.Fragment,{children:[(0,C.jsx)(`i`,{className:`fa-solid fa-shield-halved`}),` Staff Queue`]}):`My Support Tickets`}),(0,C.jsx)(`p`,{style:{color:`var(--text-secondary)`},children:s?`${s.length} ticket${s.length===1?``:`s`}`:`Loading…`})]}),(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.75rem`,flexWrap:`wrap`},children:[t.is_staff&&!e&&(0,C.jsxs)(`a`,{href:`/tickets.php?view=queue`,className:`sp-btn sp-btn-secondary`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-shield-halved`}),` Staff Queue`]}),t.is_registered?(0,C.jsxs)(`a`,{href:`/tickets.php?action=new`,className:`sp-btn sp-btn-primary`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-plus`}),` New Ticket`]}):(0,C.jsxs)(`button`,{className:`sp-btn sp-btn-primary`,disabled:!0,type:`button`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-plus`}),` New Ticket`]})]})]}),(0,C.jsx)(`form`,{className:`sp-filters`,onSubmit:e=>e.preventDefault(),children:(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.75rem`,flexWrap:`wrap`,alignItems:`center`},children:[(0,C.jsxs)(`select`,{className:`sp-select sp-select-sm`,value:r,onChange:e=>f(e.target.value,a),children:[(0,C.jsx)(`option`,{value:`all`,children:`All Statuses`}),[`open`,`in_progress`,`resolved`,`closed`].map(e=>(0,C.jsx)(`option`,{value:e,children:v[e]},e))]}),e&&(0,C.jsxs)(`select`,{className:`sp-select sp-select-sm`,value:a,onChange:e=>f(r,e.target.value),children:[(0,C.jsx)(`option`,{value:`all`,children:`All Priorities`}),[`high`,`normal`,`low`].map(e=>(0,C.jsx)(`option`,{value:e,children:e.charAt(0).toUpperCase()+e.slice(1)},e))]})]})}),u&&(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-danger`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-xmark`}),(0,C.jsx)(`span`,{children:u})]}),s&&s.length===0&&(0,C.jsxs)(`div`,{className:`sp-empty-state`,children:[(0,C.jsx)(`div`,{className:`sp-empty-icon`,children:(0,C.jsx)(`i`,{className:`fa-solid fa-ticket`})}),(0,C.jsx)(`h3`,{children:e?`No tickets match your filter`:`No tickets yet`}),(0,C.jsx)(`p`,{children:e?`Try changing the status or priority filter.`:`Submit your first support ticket if you need help.`}),!e&&t.is_registered&&(0,C.jsxs)(`a`,{href:`/tickets.php?action=new`,className:`sp-btn sp-btn-primary sp-mt-2`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-plus`}),` Submit a Ticket`]})]}),s&&s.length>0&&(0,C.jsx)(`div`,{className:`sp-table-wrap`,children:(0,C.jsxs)(`table`,{className:`sp-table`,children:[(0,C.jsx)(`thead`,{children:(0,C.jsxs)(`tr`,{children:[(0,C.jsx)(`th`,{children:`Ticket #`}),(0,C.jsx)(`th`,{children:`Subject`}),e&&(0,C.jsx)(`th`,{children:`From`}),(0,C.jsx)(`th`,{children:`Category`}),(0,C.jsx)(`th`,{children:`Priority`}),(0,C.jsx)(`th`,{children:`Status`}),(0,C.jsx)(`th`,{children:e?`Opened`:`Last Updated`})]})}),(0,C.jsx)(`tbody`,{children:s.map(t=>(0,C.jsxs)(`tr`,{children:[(0,C.jsx)(`td`,{children:(0,C.jsx)(`a`,{href:`/tickets.php?id=`+encodeURIComponent(t.ticket_number),style:{fontFamily:`monospace`,whiteSpace:`nowrap`},children:t.ticket_number})}),(0,C.jsx)(`td`,{children:(0,C.jsx)(`a`,{href:`/tickets.php?id=`+encodeURIComponent(t.ticket_number),children:t.subject})}),e&&(0,C.jsx)(`td`,{children:t.display_name||t.username}),(0,C.jsx)(`td`,{children:_[t.category]||t.category}),(0,C.jsx)(`td`,{children:ae(t.priority)}),(0,C.jsx)(`td`,{children:ie(t.status)}),(0,C.jsx)(`td`,{style:{whiteSpace:`nowrap`},children:h(e?t.created_at:t.updated_at)})]},t.ticket_number))})]})})]})}function ce({session:e}){let t=new URLSearchParams(window.location.search),[n,r]=(0,l.useState)(t.get(`category`)||`general`),[i,a]=(0,l.useState)(t.get(`program`)||``),[o,s]=(0,l.useState)(`normal`),[c,u]=(0,l.useState)(``),[d,f]=(0,l.useState)(``),[h,g]=(0,l.useState)([]),[_,v]=(0,l.useState)([]),[y,b]=(0,l.useState)(!1),x=n===`feedback`,S=n===`beta_request`;(0,l.useEffect)(()=>{p(`/api/tickets.php`).then(e=>{g(e?.beta_programs||[])})},[]);async function w(t){t.preventDefault(),b(!0),v([]);let r=await m(`/api/tickets.php`,{_action:`new_ticket`,csrf_token:e.csrf_token,category:n,program_slug:i,priority:o,subject:c,message:d});if(b(!1),r?.ok&&r.ticket_number){window.location.href=`/tickets.php?id=`+encodeURIComponent(r.ticket_number);return}v(r?.errors||[r?.error||`Could not submit ticket.`])}return(0,C.jsxs)(C.Fragment,{children:[(0,C.jsx)(`div`,{className:`sp-page-header`,children:(0,C.jsxs)(`div`,{children:[(0,C.jsxs)(`a`,{href:`/tickets.php`,className:`sp-back-link`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-arrow-left`}),` My Tickets`]}),(0,C.jsx)(`h1`,{children:`Submit a Support Ticket`})]})}),!e.is_registered&&(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-warning`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-triangle-exclamation`}),(0,C.jsxs)(`span`,{children:[`This support system is only for users of `,(0,C.jsx)(`strong`,{children:`BotOfTheSpecter`}),`. Please `,(0,C.jsx)(`a`,{href:`https://botofthespecter.com`,target:`_blank`,rel:`noopener`,children:`sign up`}),` first.`]})]}),_.map(e=>(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-danger`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-xmark`}),(0,C.jsx)(`span`,{children:e})]},e)),(0,C.jsxs)(`div`,{className:`sp-card`,style:{maxWidth:640,...e.is_registered?{}:{opacity:.5,pointerEvents:`none`}},children:[(0,C.jsxs)(`div`,{className:`sp-card-header`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-ticket`}),` New Ticket`]}),(0,C.jsx)(`div`,{className:`sp-card-body`,children:(0,C.jsxs)(`form`,{onSubmit:w,children:[(0,C.jsxs)(`div`,{className:`sp-form-row`,children:[(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsx)(`label`,{className:`sp-label`,htmlFor:`ticket_category`,children:`Category`}),(0,C.jsxs)(`select`,{id:`ticket_category`,className:`sp-select`,value:n,onChange:e=>r(e.target.value),children:[(0,C.jsx)(`option`,{value:`general`,children:`General`}),(0,C.jsx)(`option`,{value:`technical`,children:`Technical`}),(0,C.jsx)(`option`,{value:`account`,children:`Account`}),(0,C.jsx)(`option`,{value:`billing`,children:`Billing`}),(0,C.jsx)(`option`,{value:`feedback`,children:`Feedback`}),h.length>0&&(0,C.jsx)(`option`,{value:`beta_request`,children:`Beta Program Request`})]})]}),!x&&!S&&(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsx)(`label`,{className:`sp-label`,htmlFor:`ticket_priority`,children:`Priority`}),(0,C.jsxs)(`select`,{id:`ticket_priority`,className:`sp-select`,value:o,onChange:e=>s(e.target.value),children:[(0,C.jsx)(`option`,{value:`normal`,children:`Normal`}),(0,C.jsx)(`option`,{value:`low`,children:`Low`}),e.is_staff&&(0,C.jsx)(`option`,{value:`high`,children:`High`})]})]})]}),S&&(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsxs)(`label`,{className:`sp-label`,htmlFor:`ticket_program`,children:[`Beta Program `,(0,C.jsx)(`span`,{className:`sp-req`,children:`*`})]}),(0,C.jsxs)(`select`,{id:`ticket_program`,className:`sp-select`,value:i,onChange:e=>a(e.target.value),children:[(0,C.jsx)(`option`,{value:``,children:`— Select a program —`}),h.map(e=>(0,C.jsx)(`option`,{value:e.slug,children:e.name},e.slug))]})]}),!x&&!S&&(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsxs)(`label`,{className:`sp-label`,htmlFor:`ticket_subject`,children:[`Subject `,(0,C.jsx)(`span`,{className:`sp-req`,children:`*`})]}),(0,C.jsx)(`input`,{id:`ticket_subject`,className:`sp-input`,maxLength:255,placeholder:`Briefly describe your issue`,value:c,onChange:e=>u(e.target.value)})]}),(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsxs)(`label`,{className:`sp-label`,htmlFor:`ticket_message`,children:[x?`Your Feedback`:S?`Why do you want access?`:`Description`,` `,(0,C.jsx)(`span`,{className:`sp-req`,children:`*`})]}),(0,C.jsx)(`textarea`,{id:`ticket_message`,className:`sp-textarea`,rows:7,value:d,onChange:e=>f(e.target.value),placeholder:x?`Tell us what you think — what's working, what's not, or what you'd like to see improved.`:S?`Tell us a little about yourself and why you'd like to join this beta program.`:`Please describe the issue in detail - what happened, what you expected, and any error messages you saw.`}),(0,C.jsxs)(`div`,{className:`sp-char-counter`+(d.length>0&&d.length<20?` warn`:d.length>=20?` ok`:``),children:[d.length,` chars (min 20)`]})]}),(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.75rem`,flexWrap:`wrap`},children:[(0,C.jsxs)(`button`,{type:`submit`,className:`sp-btn sp-btn-primary`,disabled:y,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-paper-plane`}),` `,y?`Submitting…`:x?`Submit Feedback`:S?`Submit Request`:`Submit Ticket`]}),(0,C.jsx)(`a`,{href:`/tickets.php`,className:`sp-btn sp-btn-ghost`,children:`Cancel`})]})]})})]})]})}function le({ticketNumber:e,session:t}){let[n,r]=(0,l.useState)(null),[i,a]=(0,l.useState)([]),[o,s]=(0,l.useState)(!1),[c,u]=(0,l.useState)(``),[d,f]=(0,l.useState)(``),[y,b]=(0,l.useState)(``),[x,S]=(0,l.useState)(!1),[w,ee]=(0,l.useState)(``),[te,ne]=(0,l.useState)(!1),[T,re]=(0,l.useState)(``);function oe(){p(`/api/tickets.php?id=`+encodeURIComponent(e)).then(e=>{if(!e?.ok||!e.ticket){s(!0);return}r(e.ticket),a(e.replies||[]),f(e.ticket.status),b(e.ticket.priority)})}(0,l.useEffect)(oe,[e]);async function se(e){if(e.preventDefault(),!n)return;ne(!0);let r=await m(`/api/tickets.php`,{_action:`reply`,csrf_token:t.csrf_token,ticket_id:n.id,message:c});if(ne(!1),r?.ok){u(``),oe();return}re(r?.error||r?.errors&&r.errors[0]||`Reply failed.`)}async function ce(e){e.preventDefault(),n&&(await m(`/api/tickets.php`,{_action:`staff_update`,csrf_token:t.csrf_token,ticket_id:n.id,status:d,priority:y}),oe())}async function le(e){n&&(ne(!0),await m(`/api/tickets.php`,{_action:e,csrf_token:t.csrf_token,ticket_id:n.id,reason:w}),ne(!1),oe())}if(o)return(0,C.jsxs)(`div`,{className:`sp-empty-state`,children:[(0,C.jsx)(`div`,{className:`sp-empty-icon`,children:(0,C.jsx)(`i`,{className:`fa-solid fa-circle-xmark`})}),(0,C.jsx)(`h3`,{children:`Ticket Not Found`}),(0,C.jsx)(`p`,{children:`That ticket doesn't exist or you don't have permission to view it.`}),(0,C.jsx)(`a`,{href:`/tickets.php`,className:`sp-btn sp-btn-primary sp-mt-2`,children:`Back to My Tickets`})]});if(!n)return(0,C.jsx)(`p`,{style:{color:`var(--text-secondary)`},children:`Loading ticket…`});let E=n.meta?.program_name||n.meta?.program||`Unknown`,D=n.status===`open`||n.status===`in_progress`;return(0,C.jsxs)(C.Fragment,{children:[T&&(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-danger`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-xmark`}),(0,C.jsx)(`span`,{children:T})]}),(0,C.jsxs)(`div`,{className:`sp-page-header`,children:[(0,C.jsxs)(`div`,{children:[(0,C.jsxs)(`a`,{href:`/tickets.php`,className:`sp-back-link`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-arrow-left`}),` `,t.is_staff?`Staff Queue`:`My Tickets`]}),(0,C.jsx)(`h1`,{children:n.subject}),(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.5rem`,flexWrap:`wrap`,marginTop:`0.5rem`,alignItems:`center`},children:[(0,C.jsx)(`span`,{style:{fontFamily:`monospace`,fontSize:`0.85rem`,color:`var(--text-muted)`},children:n.ticket_number}),ie(n.status),ae(n.priority),(0,C.jsx)(`span`,{className:`sp-badge sp-cat`,children:_[n.category]||n.category})]})]}),t.is_staff&&(0,C.jsxs)(`form`,{onSubmit:ce,style:{display:`flex`,gap:`0.5rem`,alignItems:`center`,flexWrap:`wrap`},children:[(0,C.jsx)(`select`,{className:`sp-select sp-select-sm`,value:d,onChange:e=>f(e.target.value),children:[`open`,`in_progress`,`resolved`,`closed`].map(e=>(0,C.jsx)(`option`,{value:e,children:v[e]},e))}),(0,C.jsx)(`select`,{className:`sp-select sp-select-sm`,value:y,onChange:e=>b(e.target.value),children:[`low`,`normal`,`high`].map(e=>(0,C.jsx)(`option`,{value:e,children:e.charAt(0).toUpperCase()+e.slice(1)},e))}),(0,C.jsxs)(`button`,{type:`submit`,className:`sp-btn sp-btn-secondary sp-btn-sm`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-check`}),` Update`]})]})]}),(0,C.jsxs)(`div`,{className:`sp-ticket-meta`,children:[(0,C.jsxs)(`span`,{children:[(0,C.jsx)(`i`,{className:`fa-regular fa-user`}),` Opened by `,(0,C.jsx)(`strong`,{children:n.display_name||n.username})]}),(0,C.jsxs)(`span`,{children:[(0,C.jsx)(`i`,{className:`fa-regular fa-clock`}),` `,g(n.created_at)]}),(0,C.jsxs)(`span`,{children:[(0,C.jsx)(`i`,{className:`fa-regular fa-rotate`}),` Updated `,h(n.updated_at)]}),n.category===`beta_request`&&(0,C.jsxs)(`span`,{children:[(0,C.jsx)(`i`,{className:`fa-solid fa-flask`}),` Program: `,(0,C.jsx)(`strong`,{children:E})]})]}),t.is_staff&&n.category===`beta_request`&&D&&(0,C.jsxs)(`div`,{className:`sp-card sp-mt-3`,style:{borderLeft:`3px solid var(--accent,#7c3aed)`},children:[(0,C.jsxs)(`div`,{className:`sp-card-header`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-flask`}),` Beta Request — `,E]}),(0,C.jsx)(`div`,{className:`sp-card-body`,children:(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`1rem`,flexWrap:`wrap`},children:[(0,C.jsxs)(`button`,{type:`button`,className:`sp-btn sp-btn-primary`,disabled:te,onClick:()=>le(`approve_beta`),children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-check`}),` Approve`]}),(0,C.jsxs)(`div`,{children:[(0,C.jsxs)(`button`,{type:`button`,className:`sp-btn sp-btn-danger`,onClick:()=>S(!0),children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-xmark`}),` Decline`]}),x&&(0,C.jsxs)(`div`,{style:{marginTop:`0.75rem`},children:[(0,C.jsx)(`div`,{className:`sp-form-group`,children:(0,C.jsx)(`textarea`,{className:`sp-textarea`,rows:3,placeholder:`Optional: reason for declining…`,value:w,onChange:e=>ee(e.target.value)})}),(0,C.jsxs)(`button`,{type:`button`,className:`sp-btn sp-btn-danger`,disabled:te,onClick:()=>le(`decline_beta`),children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-xmark`}),` Confirm Decline`]})]})]})]})})]}),(0,C.jsx)(`div`,{className:`sp-ticket-thread`,children:i.map(e=>{let t=!!e.is_staff;return(0,C.jsxs)(`div`,{className:t?`sp-msg sp-msg-staff`:`sp-msg sp-msg-user`,children:[(0,C.jsxs)(`div`,{className:`sp-msg-header`,children:[(0,C.jsxs)(`span`,{className:`sp-msg-author`,children:[t&&(0,C.jsxs)(`span`,{className:`sp-badge sp-staff-badge`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-shield-halved`}),` Staff `]}),e.author_display_name]}),(0,C.jsx)(`span`,{className:`sp-msg-time`,children:g(e.created_at)})]}),(0,C.jsx)(`div`,{className:`sp-msg-body`,style:{whiteSpace:`pre-wrap`},children:e.message})]},e.id)})}),D?(0,C.jsxs)(`div`,{className:`sp-card sp-mt-3`,children:[(0,C.jsxs)(`div`,{className:`sp-card-header`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-reply`}),` Reply`]}),(0,C.jsx)(`div`,{className:`sp-card-body`,children:(0,C.jsxs)(`form`,{onSubmit:se,children:[(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsx)(`label`,{className:`sp-label`,htmlFor:`reply_msg`,children:`Message`}),(0,C.jsx)(`textarea`,{id:`reply_msg`,className:`sp-textarea`,rows:5,value:c,onChange:e=>u(e.target.value),placeholder:`Write your reply here…`})]}),(0,C.jsxs)(`button`,{type:`submit`,className:`sp-btn sp-btn-primary`,disabled:te,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-paper-plane`}),` Send Reply`]})]})})]}):(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-info sp-mt-3`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-lock`}),(0,C.jsxs)(`span`,{children:[`This ticket is `,(0,C.jsx)(`strong`,{children:v[n.status]||n.status}),`. `,!t.is_staff&&`Replying will automatically reopen it.`]}),!t.is_staff&&(0,C.jsxs)(`form`,{onSubmit:se,style:{marginTop:`0.75rem`},children:[(0,C.jsx)(`div`,{className:`sp-form-group`,children:(0,C.jsx)(`textarea`,{className:`sp-textarea`,rows:4,value:c,onChange:e=>u(e.target.value),placeholder:`Write a follow-up…`})}),(0,C.jsxs)(`button`,{type:`submit`,className:`sp-btn sp-btn-secondary`,disabled:te,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-paper-plane`}),` Reopen & Reply`]})]})]})]})}function E({session:e}){let[t,n]=(0,l.useState)([]),[r,i]=(0,l.useState)([]),[a,o]=(0,l.useState)([]),[s,c]=(0,l.useState)([]),[u,d]=(0,l.useState)(null),[f,h]=(0,l.useState)(0),[_,v]=(0,l.useState)(``),[y,b]=(0,l.useState)(``),[x,S]=(0,l.useState)(``),[w,ee]=(0,l.useState)(!1);function te(){p(`/api/beta.php`).then(e=>{n(e?.programs||[]),i(e?.enrolled||[]),o(e?.pending||[]),c(e?.pending_requests||[])})}(0,l.useEffect)(te,[]);async function ne(t){ee(!0);let n=await m(`/api/beta.php`,{...t,csrf_token:e.csrf_token});if(ee(!1),n?.ok){d({type:`success`,msg:n.message||`Saved.`}),T(),te();return}d({type:`danger`,msg:n?.error||n?.errors&&n.errors[0]||`Request failed.`})}function T(){h(0),v(``),b(``),S(``)}function re(e){h(e.id),b(e.name),S(e.description||``),document.getElementById(`program-card`)?.scrollIntoView({behavior:`smooth`})}function ie(e){e.preventDefault(),ne({_action:`save_program`,edit_id:f,slug:_,name:y,description:x})}return(0,C.jsxs)(C.Fragment,{children:[u&&(0,C.jsxs)(`div`,{className:`sp-alert sp-alert-`+u.type,children:[(0,C.jsx)(`i`,{className:`fa-solid `+(u.type===`success`?`fa-circle-check`:`fa-circle-xmark`)}),(0,C.jsx)(`span`,{children:u.msg})]}),(0,C.jsx)(`div`,{className:`sp-page-header`,children:(0,C.jsxs)(`div`,{children:[(0,C.jsxs)(`h1`,{children:[(0,C.jsx)(`i`,{className:`fa-solid fa-flask`}),` Beta Programs`]}),(0,C.jsx)(`p`,{style:{color:`var(--text-secondary)`},children:`Request early access to features currently in testing.`})]})}),t.length===0?(0,C.jsxs)(`div`,{className:`sp-empty-state`,children:[(0,C.jsx)(`div`,{className:`sp-empty-icon`,children:(0,C.jsx)(`i`,{className:`fa-solid fa-flask`})}),(0,C.jsx)(`h3`,{children:`No Beta Programs Available`}),(0,C.jsx)(`p`,{children:`There are no beta programs open right now. Check back later.`})]}):(0,C.jsx)(`div`,{style:{display:`grid`,gridTemplateColumns:`repeat(auto-fill,minmax(300px,1fr))`,gap:`1rem`,marginBottom:`2rem`},children:t.map(t=>{let n=r.includes(t.slug),i=a.includes(t.slug),o=!t.is_active;return(0,C.jsxs)(`div`,{className:`sp-card`,style:o?{opacity:.55}:void 0,children:[(0,C.jsxs)(`div`,{className:`sp-card-header`,style:{display:`flex`,alignItems:`center`,justifyContent:`space-between`,gap:`0.5rem`,flexWrap:`wrap`},children:[(0,C.jsx)(`span`,{children:t.name}),(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.3rem`,alignItems:`center`},children:[o&&(0,C.jsx)(`span`,{className:`sp-badge`,style:{background:`var(--text-muted)`,color:`#fff`,fontSize:`0.7rem`},children:`Inactive`}),n&&(0,C.jsxs)(`span`,{className:`sp-badge sp-badge-green`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-circle-check`}),` Enrolled`]}),i&&(0,C.jsxs)(`span`,{className:`sp-badge sp-badge-amber`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-clock`}),` Pending`]})]})]}),(0,C.jsxs)(`div`,{className:`sp-card-body`,children:[t.description&&(0,C.jsx)(`p`,{style:{color:`var(--text-secondary)`,marginBottom:`1rem`,fontSize:`0.9rem`,whiteSpace:`pre-wrap`},children:t.description}),(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.5rem`,flexWrap:`wrap`,alignItems:`center`},children:[(0,C.jsx)(`code`,{style:{fontSize:`0.72rem`,color:`var(--text-muted)`},children:t.slug}),!o&&!n&&!i&&(0,C.jsxs)(`a`,{href:`/tickets.php?action=new&category=beta_request&program=`+encodeURIComponent(t.slug),className:`sp-btn sp-btn-primary sp-btn-sm`,style:{marginLeft:`auto`},children:[(0,C.jsx)(`i`,{className:`fa-solid fa-paper-plane`}),` Request Access`]}),e.is_staff&&(0,C.jsxs)(`div`,{style:{marginLeft:`auto`,display:`flex`,gap:`0.25rem`},children:[(0,C.jsx)(`button`,{type:`button`,className:`sp-btn sp-btn-sm`,title:`Edit`,onClick:()=>re(t),children:(0,C.jsx)(`i`,{className:`fa-solid fa-pen`})}),(0,C.jsx)(`button`,{type:`button`,className:`sp-btn sp-btn-sm`,title:o?`Activate`:`Deactivate`,disabled:w,onClick:()=>ne({_action:`toggle_program`,program_id:t.id}),children:(0,C.jsx)(`i`,{className:`fa-solid `+(o?`fa-eye`:`fa-eye-slash`)})}),(0,C.jsx)(`button`,{type:`button`,className:`sp-btn sp-btn-danger sp-btn-sm`,title:`Delete`,disabled:w,onClick:()=>{confirm(`Delete this program? This cannot be undone.`)&&ne({_action:`delete_program`,program_id:t.id})},children:(0,C.jsx)(`i`,{className:`fa-solid fa-trash`})})]})]})]})]},t.slug)})}),e.is_staff&&(0,C.jsxs)(C.Fragment,{children:[(0,C.jsxs)(`div`,{className:`sp-card sp-mt-3`,style:{maxWidth:560},id:`program-card`,children:[(0,C.jsxs)(`div`,{className:`sp-card-header`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-plus`}),` `,(0,C.jsx)(`span`,{children:f?`Edit Beta Program`:`Create Beta Program`})]}),(0,C.jsx)(`div`,{className:`sp-card-body`,children:(0,C.jsxs)(`form`,{onSubmit:ie,children:[f===0&&(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsxs)(`label`,{className:`sp-label`,htmlFor:`prog_slug`,children:[`Slug `,(0,C.jsx)(`span`,{className:`sp-req`,children:`*`}),(0,C.jsx)(`span`,{style:{fontSize:`0.75rem`,color:`var(--text-muted)`},children:` — this becomes the program key (lowercase, no spaces)`})]}),(0,C.jsx)(`input`,{id:`prog_slug`,className:`sp-input`,value:_,onChange:e=>v(e.target.value),placeholder:`e.g. streaming`,maxLength:50,pattern:`[a-z0-9_-]+`,title:`Lowercase letters, numbers, hyphens and underscores only`})]}),(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsxs)(`label`,{className:`sp-label`,htmlFor:`prog_name`,children:[`Name `,(0,C.jsx)(`span`,{className:`sp-req`,children:`*`})]}),(0,C.jsx)(`input`,{id:`prog_name`,className:`sp-input`,value:y,onChange:e=>b(e.target.value),placeholder:`e.g. Streaming Beta`,maxLength:100})]}),(0,C.jsxs)(`div`,{className:`sp-form-group`,children:[(0,C.jsx)(`label`,{className:`sp-label`,htmlFor:`prog_desc`,children:`Description`}),(0,C.jsx)(`textarea`,{id:`prog_desc`,className:`sp-textarea`,rows:3,value:x,onChange:e=>S(e.target.value),placeholder:`What does this beta program test?`})]}),(0,C.jsxs)(`div`,{style:{display:`flex`,gap:`0.5rem`},children:[(0,C.jsxs)(`button`,{type:`submit`,className:`sp-btn sp-btn-primary`,disabled:w,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-floppy-disk`}),` `,f?`Save Changes`:`Save Program`]}),f>0&&(0,C.jsx)(`button`,{type:`button`,className:`sp-btn sp-btn-ghost`,onClick:T,children:`Cancel`})]})]})})]}),s.length>0&&(0,C.jsxs)(`div`,{className:`sp-card sp-mt-3`,children:[(0,C.jsxs)(`div`,{className:`sp-card-header`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-clock`}),` Pending Requests`,(0,C.jsx)(`span`,{className:`sp-badge sp-badge-amber`,style:{marginLeft:`0.5rem`},children:s.length})]}),(0,C.jsx)(`div`,{className:`sp-table-wrap`,children:(0,C.jsxs)(`table`,{className:`sp-table`,children:[(0,C.jsx)(`thead`,{children:(0,C.jsxs)(`tr`,{children:[(0,C.jsx)(`th`,{children:`Ticket`}),(0,C.jsx)(`th`,{children:`User`}),(0,C.jsx)(`th`,{children:`Program`}),(0,C.jsx)(`th`,{children:`Submitted`}),(0,C.jsx)(`th`,{})]})}),(0,C.jsx)(`tbody`,{children:s.map(e=>(0,C.jsxs)(`tr`,{children:[(0,C.jsx)(`td`,{children:(0,C.jsx)(`a`,{href:`/tickets.php?id=`+encodeURIComponent(e.ticket_number),style:{fontFamily:`monospace`,whiteSpace:`nowrap`},children:e.ticket_number})}),(0,C.jsx)(`td`,{children:e.display_name||e.username}),(0,C.jsx)(`td`,{children:(0,C.jsx)(`span`,{className:`sp-badge sp-badge-blue`,children:e.program_name})}),(0,C.jsx)(`td`,{style:{whiteSpace:`nowrap`},children:g(e.created_at)}),(0,C.jsx)(`td`,{children:(0,C.jsxs)(`a`,{href:`/tickets.php?id=`+encodeURIComponent(e.ticket_number),className:`sp-btn sp-btn-sm sp-btn-secondary`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-eye`}),` Review`]})})]},e.ticket_number))})]})})]})]})]})}var D=[{href:`/index.php`,icon:`fa-solid fa-house`,label:`Home`},{href:`/index.php#setup`,icon:`fa-solid fa-rocket`,label:`First Time Setup`,hash:`setup`},{href:`/index.php#features`,icon:`fa-solid fa-star`,label:`Main Features`,hash:`features`},{href:`/index.php#spotify`,icon:`fa-brands fa-spotify`,label:`Spotify Setup`,hash:`spotify`},{href:`/index.php#tts`,icon:`fa-solid fa-microphone`,label:`Text-to-Speech`,hash:`tts`},{href:`/index.php#obs-audio`,icon:`fa-solid fa-headphones`,label:`OBS Audio Monitoring`,hash:`obs-audio`},{href:`/index.php#variables`,icon:`fa-solid fa-code`,label:`Variables`,hash:`variables`},{href:`/index.php#twitch-channel-points`,icon:`fa-brands fa-twitch`,label:`Channel Points`,hash:`twitch-channel-points`},{href:`/index.php#api`,icon:`fa-solid fa-satellite-dish`,label:`Custom API`,hash:`api`},{href:`/index.php#run-yourself`,icon:`fa-solid fa-server`,label:`Run Yourself`,hash:`run-yourself`},{href:`/index.php#commands`,icon:`fa-solid fa-terminal`,label:`Command Reference`,hash:`commands`},{href:`/index.php#faq`,icon:`fa-solid fa-circle-question`,label:`FAQ`,hash:`faq`},{href:`/index.php#troubleshooting`,icon:`fa-solid fa-wrench`,label:`Troubleshooting`,hash:`troubleshooting`}];function ue(){let e=window.location.pathname;return e.includes(`tickets.php`)?`tickets`:e.includes(`beta.php`)?`beta`:`docs`}var de={ok:!1,logged_in:!1,is_staff:!1,is_registered:!1,username:null,display_name:null,profile_image:null,csrf_token:null,dashboard_version:``};function fe(){let e=ue(),[t,n]=(0,l.useState)(null),[r,i]=(0,l.useState)(!1),[a,o]=(0,l.useState)(()=>y()),[s,c]=(0,l.useState)(()=>window.location.hash.replace(`#`,``));(0,l.useEffect)(()=>{b(a,!1)},[a]),(0,l.useEffect)(()=>{f().then(n).catch(()=>n(de))},[]),(0,l.useEffect)(()=>{let e=()=>c(window.location.hash.replace(`#`,``));return window.addEventListener(`hashchange`,e),()=>window.removeEventListener(`hashchange`,e)},[]);let u=!!t?.logged_in,d=!!t?.is_staff,p=t?.display_name||t?.username||``,m=e===`tickets`?`Support Tickets`:e===`beta`?`Beta Programs`:`BotOfTheSpecter Documentation`;return(0,C.jsxs)(C.Fragment,{children:[(0,C.jsx)(`div`,{id:`sp-sidebar-overlay`,className:`sp-sidebar-overlay`+(r?` open`:``),onClick:()=>i(!1)}),(0,C.jsxs)(`div`,{className:`sp-layout`,children:[(0,C.jsxs)(`aside`,{id:`sp-sidebar`,className:`sp-sidebar`+(r?` open`:``),children:[(0,C.jsxs)(`div`,{className:`sp-brand`,children:[(0,C.jsx)(`img`,{src:`https://cdn.botofthespecter.com/logo.png`,alt:`BotOfTheSpecter`}),(0,C.jsxs)(`div`,{className:`sp-brand-text`,children:[(0,C.jsx)(`span`,{className:`sp-brand-title`,children:`BotOfTheSpecter`}),(0,C.jsx)(`span`,{className:`sp-brand-sub`,children:`Support Portal`})]})]}),(0,C.jsxs)(`nav`,{className:`sp-nav`,children:[(0,C.jsxs)(`div`,{className:`sp-nav-section`,children:[(0,C.jsx)(`div`,{className:`sp-nav-label`,children:`Documentation`}),D.map(t=>{let n=e===`docs`&&(t.hash&&s===t.hash||!t.hash&&!s&&t.href===`/index.php`);return(0,C.jsxs)(`a`,{href:t.href,className:`sp-nav-link`+(n?` active`:``),children:[(0,C.jsx)(`i`,{className:t.icon}),` `,t.label]},t.href)})]}),(0,C.jsxs)(`div`,{className:`sp-nav-section`,children:[(0,C.jsx)(`div`,{className:`sp-nav-label`,children:`Resources`}),(0,C.jsxs)(`a`,{href:`https://api.botofthespecter.com/docs`,target:`_blank`,rel:`noopener`,className:`sp-nav-link`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-book`}),` API Docs `,(0,C.jsx)(`i`,{className:`fa-solid fa-arrow-up-right-from-square`,style:{fontSize:`0.65rem`,opacity:.5,marginLeft:`auto`}})]}),(0,C.jsxs)(`a`,{href:`https://github.com/YourStreamingTools/BotOfTheSpecter`,target:`_blank`,rel:`noopener`,className:`sp-nav-link`,children:[(0,C.jsx)(`i`,{className:`fa-brands fa-github`}),` GitHub `,(0,C.jsx)(`i`,{className:`fa-solid fa-arrow-up-right-from-square`,style:{fontSize:`0.65rem`,opacity:.5,marginLeft:`auto`}})]}),(0,C.jsxs)(`a`,{href:`https://dashboard.botofthespecter.com/dashboard.php`,target:`_blank`,rel:`noopener`,className:`sp-nav-link`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-gauge`}),` Dashboard `,(0,C.jsx)(`i`,{className:`fa-solid fa-arrow-up-right-from-square`,style:{fontSize:`0.65rem`,opacity:.5,marginLeft:`auto`}})]})]}),(0,C.jsxs)(`div`,{className:`sp-nav-section`,children:[(0,C.jsx)(`div`,{className:`sp-nav-label`,children:`Support`}),u?(0,C.jsxs)(C.Fragment,{children:[(0,C.jsxs)(`a`,{href:`/tickets.php`,className:`sp-nav-link`+(e===`tickets`?` active`:``),children:[(0,C.jsx)(`i`,{className:`fa-solid fa-ticket`}),` My Tickets`]}),(0,C.jsxs)(`a`,{href:`/tickets.php?action=new`,className:`sp-nav-link`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-plus`}),` Submit a Ticket`]}),(0,C.jsxs)(`a`,{href:`/beta.php`,className:`sp-nav-link`+(e===`beta`?` active`:``),children:[(0,C.jsx)(`i`,{className:`fa-solid fa-flask`}),` Beta Programs`]}),d&&(0,C.jsxs)(`a`,{href:`/tickets.php?view=queue`,className:`sp-nav-link sp-nav-link-staff`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-headset`}),` Staff Queue `,(0,C.jsx)(`span`,{className:`sp-badge sp-badge-accent`,style:{marginLeft:`auto`,fontSize:`0.65rem`},children:`Staff`})]})]}):(0,C.jsxs)(C.Fragment,{children:[(0,C.jsxs)(`a`,{href:`/login.php`,className:`sp-nav-link`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-right-to-bracket`}),` Log in to Submit`]}),(0,C.jsxs)(`a`,{href:`/beta.php`,className:`sp-nav-link`+(e===`beta`?` active`:``),children:[(0,C.jsx)(`i`,{className:`fa-solid fa-flask`}),` Beta Programs`]})]})]})]}),(0,C.jsx)(`div`,{className:`sp-sidebar-footer`,children:u?(0,C.jsxs)(C.Fragment,{children:[(0,C.jsxs)(`div`,{className:`sp-user-block`,children:[t?.profile_image?(0,C.jsx)(`img`,{src:t.profile_image,alt:p,className:`sp-user-avatar`}):(0,C.jsx)(`div`,{className:`sp-user-avatar-placeholder`,children:(0,C.jsx)(`i`,{className:`fa-solid fa-user`})}),(0,C.jsxs)(`div`,{style:{minWidth:0},children:[(0,C.jsx)(`div`,{className:`sp-user-name`,children:p}),(0,C.jsx)(`div`,{className:`sp-user-role`,children:d?`Staff`:`User`})]})]}),(0,C.jsxs)(`a`,{href:`/logout.php`,className:`sp-nav-link sp-text-small`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-right-from-bracket`}),` Log Out`]})]}):(0,C.jsxs)(`a`,{href:`/login.php`,className:`sp-btn sp-btn-primary`,style:{width:`100%`,justifyContent:`center`},children:[(0,C.jsx)(`i`,{className:`fa-solid fa-right-to-bracket`}),` Log In`]})})]}),(0,C.jsxs)(`div`,{className:`sp-main`,children:[(0,C.jsxs)(`header`,{className:`sp-topbar`,children:[(0,C.jsx)(`button`,{className:`sp-hamburger`,"aria-label":`Open menu`,type:`button`,onClick:()=>i(e=>!e),children:(0,C.jsx)(`i`,{className:`fa-solid fa-bars`})}),(0,C.jsx)(`span`,{className:`sp-topbar-title`,children:m}),(0,C.jsxs)(`div`,{className:`sp-topbar-actions`,children:[(0,C.jsx)(`button`,{className:`sp-theme-toggle`,type:`button`,"aria-label":`Toggle light or dark theme`,onClick:()=>{let e=a===`light`?`dark`:`light`;b(e,!0),o(e)},children:(0,C.jsx)(`i`,{className:a===`light`?`fa-solid fa-sun`:`fa-solid fa-moon`})}),u&&!d&&e===`docs`&&(0,C.jsxs)(`a`,{href:`/tickets.php?action=new`,className:`sp-btn sp-btn-primary sp-btn-sm`,children:[(0,C.jsx)(`i`,{className:`fa-solid fa-plus`}),` New Ticket`]}),!u&&(0,C.jsxs)(`a`,{href:`/login.php`,className:`sp-btn sp-btn-secondary sp-btn-sm`,children:[(0,C.jsx)(`i`,{className:`fa-brands fa-twitch`}),` Log In`]})]})]}),(0,C.jsxs)(`main`,{className:`sp-content`,children:[e===`docs`&&(0,C.jsx)(T,{loggedIn:u}),e===`tickets`&&t&&(0,C.jsx)(oe,{session:t}),e===`beta`&&t&&(0,C.jsx)(E,{session:t}),(e===`tickets`||e===`beta`)&&!t&&(0,C.jsx)(`p`,{style:{color:`var(--text-secondary)`},children:`Loading…`})]}),(0,C.jsxs)(`footer`,{className:`sp-footer`,children:[`© 2023–`,new Date().getFullYear(),` BotOfTheSpecter. All rights reserved.`,(0,C.jsx)(`br`,{}),`BotOfTheSpecter is operated under the business name "YourStreamingTools", registered in Australia (ABN\xA020\xA0447\xA0022\xA0747).`,(0,C.jsx)(`br`,{}),`Not affiliated with Twitch Interactive, Inc., Discord Inc., Spotify AB, or StreamElements Inc.`,(0,C.jsx)(`br`,{}),`All trademarks are the property of their respective owners.`,t?.dashboard_version&&(0,C.jsxs)(C.Fragment,{children:[(0,C.jsx)(`br`,{}),(0,C.jsxs)(`span`,{style:{color:`var(--text-muted)`,fontSize:`0.72rem`},children:[`Portal v`,t.dashboard_version]})]})]})]})]})]})}(0,u.createRoot)(document.getElementById(`root`)).render((0,C.jsx)(l.StrictMode,{children:(0,C.jsx)(fe,{})}));