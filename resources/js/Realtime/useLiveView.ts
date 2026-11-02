import {useCallback,useEffect,useRef,useState} from 'react';
import {usePage} from '@inertiajs/react';
import axios from 'axios';
import {echo} from '@laravel/echo-react';
import {applyLiveDelta, type LiveOp} from './liveDelta';

type Frame={viewId:string;revoked?:boolean;retry?:boolean;batch:string;base:number;revision:number;part:number;total:number;chunk:string};
type State<T>={revision:number;data:T};
/** Keep brief tab switches connected; suspend after 30 seconds hidden. Updates carry deltas. */
export function useLiveView<T>(url:string|null,onData:(data:T)=>void,mode='page') {
    const auth=usePage().props.auth as any;
    const owner=JSON.stringify([auth?.user?.id,auth?.realtime_channel]);
    const callback=useRef(onData);callback.current=onData;
    const syncRef=useRef<()=>void>(()=>{});
    const [status,setStatus]=useState<'idle'|'connecting'|'live'|'offline'|'denied'>('idle');
    useEffect(()=> {
        if(!url){setStatus('idle');return;}
        setStatus('connecting');
        const isHidden=()=>document.visibilityState==='hidden';
        let epoch=0,starting=false;
        let active=true,id:string|null=null,channel:any=null,renew:ReturnType<typeof setTimeout>|null=null;
        let hiddenTimer:ReturnType<typeof setTimeout>|null=null;
        let denied=false,retryAt=0,lastStatus='connecting',failures=0;
        let recovery:ReturnType<typeof setTimeout>|null=null;
        const cancelRecovery=()=>{if(recovery)clearTimeout(recovery);recovery=null;};
        const scheduleRecovery=()=>{
            // Retry only a failed incident, at most three attempts. Healthy views never poll.
            if(!active || denied || isHidden() || !retryAt || failures>=3 || recovery)return;
            recovery=setTimeout(()=>{recovery=null;refresh();},Math.max(0,retryAt-Date.now()));
        };
        const statusChanged=(next:'connecting'|'live'|'offline'|'denied')=>{lastStatus=next;if(active)setStatus(next);};
        const failed=(error:any)=> {
            const code=error?.response?.status ?? error?.status;
            denied=[401,403].includes(code);
            const header=error?.response?.headers?.['retry-after'];
            const seconds=Number(header);
            const deadline=header && !Number.isFinite(seconds)?Date.parse(header):Date.now()+Math.max(0,seconds || 0)*1000;
            retryAt=code===429 || (code===503 && header)
                ? Math.max(Date.now()+1000,Number.isFinite(deadline) && header?deadline:Date.now()+(code===429?60000:30000))
                : Date.now()+(code===404?1000:30000);
            failures++;
            statusChanged(denied?'denied':'offline');
            if(denied || code===404)void close();
            scheduleRecovery();
        };
        let state:State<T>|null=null,syncing=false,resync=false,retryPending=false,lastRecoveryRevision=0;
        const controller=new AbortController();
        const batches=new Map<string,{frame:Frame;parts:Map<number,string>;at:number;timeout:ReturnType<typeof setTimeout>}>();
        const queued:{base:number;revision:number;ops:LiveOp[]}[]=[];
        const recoveryEvidence=(revision:number)=>{
            if(denied || revision<=lastRecoveryRevision)return false;
            lastRecoveryRevision=revision;
            if(!failures && lastStatus==='live')return false;
            failures=0;retryPending=false;cancelRecovery();
            // Fresh private data proves recovery, but never cancels an HTTP throttle deadline.
            return true;
        };
        const requestSnapshot=()=>{
            resync=true;
            if(isHidden())return;
            if(Date.now()<retryAt){scheduleRecovery();return;}
            void synchronize();
        };
        const commit=(update:{base:number;revision:number;ops:LiveOp[]})=> {
            if(!state || syncing){
                if(queued.length<1000)queued.push(update);else resync=true;
                if(!syncing && recoveryEvidence(update.revision))requestSnapshot();
                return;
            }
            if(update.revision<=state.revision)return;
            if(update.base!==state.revision){recoveryEvidence(update.revision);requestSnapshot();return;}
            try {
                state={revision:update.revision,data:applyLiveDelta(state.data,update.ops)};callback.current(state.data);
                if(recoveryEvidence(update.revision)){
                    statusChanged('live');
                    if(!renew)requestSnapshot();
                }
            }
            catch {resync=true;void synchronize();}
        };
        const receive=(frame:Frame)=> {
            if(!active || frame.viewId!==id)return;
            if(frame.revoked){denied=true;statusChanged('denied');void close();return;}
            if(frame.retry){
                if(denied)return;
                if(syncing)resync=true;
                else if(!retryPending){
                    retryPending=true;
                    if(Date.now()<retryAt)scheduleRecovery();
                    else failed({status:503,response:{status:503,headers:{'retry-after':'5'}}});
                }else scheduleRecovery();
                return;
            }
            if(!Number.isSafeInteger(frame.revision) || !Number.isSafeInteger(frame.base) || frame.base<0 || frame.revision<=frame.base
                || !Number.isSafeInteger(frame.total) || !Number.isSafeInteger(frame.part) || frame.total<1 || frame.total>2048 || frame.part<0 || frame.part>=frame.total)return;
            if(state && frame.revision<=state.revision)return;
            for(const [key,value] of batches)if(Date.now()-value.at>30000){clearTimeout(value.timeout);batches.delete(key);}
            if(batches.size>30){for(const batch of batches.values())clearTimeout(batch.timeout);batches.clear();resync=true;void synchronize();return;}
            const batch=batches.get(frame.batch) || {frame,parts:new Map<number,string>(),at:Date.now(),timeout:setTimeout(()=>{if(batches.delete(frame.batch)){resync=true;void synchronize();}},15000)};
            if(batch.frame.total!==frame.total || batch.frame.revision!==frame.revision || batch.frame.base!==frame.base)return;
            batch.parts.set(frame.part,frame.chunk);batches.set(frame.batch,batch);
            if(batch.parts.size!==frame.total)return;
            clearTimeout(batch.timeout);batches.delete(frame.batch);
            try {
                const binary=Array.from({length:frame.total},(_,part)=>atob(batch.parts.get(part)!)).join('');
                const ops=JSON.parse(new TextDecoder().decode(Uint8Array.from(binary,char=>char.charCodeAt(0))));
                if(!Array.isArray(ops) || !ops.length || !ops.every(op=>op && ['set','remove','rows'].includes(op.op)
                    && Array.isArray(op.path) && op.path.every((key:unknown)=>(typeof key==='string' || Number.isSafeInteger(key))
                        && !['__proto__','prototype','constructor'].includes(String(key)))
                    && (op.op!=='rows' || (Array.isArray(op.rows) && Array.isArray(op.order)))))throw new Error('Invalid live operations');
                commit({base:frame.base,revision:frame.revision,ops});
            } catch {resync=true;void synchronize();}
        };
        async function synchronize() {
            if(!active || denied || !id || Date.now()<retryAt || failures>=3)return;
            if(isHidden()){resync=true;return;}
            if(syncing){resync=true;return;}syncing=true;resync=false;
            const currentId=id,currentEpoch=epoch;let success=false;
            try {
                const {data}=await axios.post<State<T>>(`/admin/live-views/${currentId}/sync`,{}, {signal:controller.signal});
                if(!active || currentEpoch!==epoch || currentId!==id)return;
                if(!data || !Number.isSafeInteger(data.revision) || data.revision<0 || typeof data.data!=='object' || data.data===null)throw new Error('Invalid live snapshot');
                success=true;
                if(!state || data.revision>=state.revision){state=data;callback.current(data.data);}
                retryAt=0;failures=0;retryPending=false;cancelRecovery();lease();statusChanged('live');
            } catch(error:any) {if(active && currentEpoch===epoch && currentId===id)failed(error);}
            finally {
                if(currentEpoch!==epoch)return;
                syncing=false;
                if(!success){resync=false;return;}
                const pending=queued.splice(0).sort((a,b)=>a.revision-b.revision);
                for(const update of pending)commit(update);
                // A revision gap schedules one recovery snapshot; never a periodic data fetch.
                if(resync && active && state){resync=false;void synchronize();}
            }
        }
        function lease() {
            // Control-plane renewal only: no page data or balance is fetched.
            if(renew || !active || !id)return;
            renew=setTimeout(async()=> {
                renew=null;
                if(!active || !id)return;
                const currentId=id,currentEpoch=epoch;
                try {await axios.patch(`/admin/live-views/${currentId}`,{}, {signal:controller.signal});if(active && currentEpoch===epoch && currentId===id)lease();}
                catch(error:any) {if(active && currentEpoch===epoch && currentId===id)failed(error);}
            },15*60*1000);
        }
        async function close() {
            epoch++;starting=false;syncing=false;retryPending=false;state=null;queued.length=0;
            cancelRecovery();
            for(const batch of batches.values())clearTimeout(batch.timeout);batches.clear();
            if(renew)clearTimeout(renew);renew=null;
            if(channel){try{channel.stopListening('.AdminViewPatched',receive);echo().leave(`Admin.View.${id}`);}catch{/* Cleanup still deletes the lease if the socket has gone away. */}channel=null;}
            if(id){const old=id;id=null;try{await axios.delete(`/admin/live-views/${old}`);}catch{/* Server lease expires if disconnect prevents cleanup. */}}
        }
        async function start() {
            if(!active || starting || id || isHidden() || Date.now()<retryAt)return;
            starting=true;const currentEpoch=epoch;
            statusChanged('connecting');
            try {
                const {data}=await axios.post<{id:string;channel:string}>('/admin/live-views',{url,mode},{signal:controller.signal});
                if(!data || typeof data.id!=='string' || typeof data.channel!=='string')throw new Error('Invalid live registration');
                if(!active || currentEpoch!==epoch || isHidden()){void axios.delete(`/admin/live-views/${data.id}`).catch(()=>{});return;}
                id=data.id;state=null;channel=echo().private(data.channel);
                channel.listen('.AdminViewPatched',receive);
                channel.on('pusher:subscription_succeeded',()=>{if(active && currentEpoch===epoch)void synchronize();});
                channel.error((error:unknown)=>{if(active && currentEpoch===epoch){void close();failed(error);}});
                if(channel.subscription?.subscribed)void synchronize();
                lease();
            } catch(error:any){if(active && currentEpoch===epoch){if(id && !channel)void close();failed(error);}}
            finally {if(currentEpoch===epoch)starting=false;}
        }
        const refresh=(manual=false)=>{
            if(!active || isHidden() || (!manual && (denied || failures>=3)))return;
            if(Date.now()<retryAt){scheduleRecovery();return;}
            if(manual){denied=false;failures=0;}
            if(id)void synchronize();else void start();
        };
        syncRef.current=()=>refresh(true);
        const online=()=>{if(active && !denied){failures=0;refresh();}};
        const visible=()=>{
            if(hiddenTimer)clearTimeout(hiddenTimer);
            if(isHidden())hiddenTimer=setTimeout(()=>{if(active && isHidden()){void close();statusChanged(denied?'denied':'offline');}},30000);
            else if(!id || lastStatus!=='live' || resync)refresh();
        };
        let connection:any;
        try{connection=(echo() as any).connector?.pusher?.connection;}catch{/* A missing socket config must not break ordinary page reads. */}
        const connectionChanged=(change:{current?:string})=>{
            if(!active)return;
            if(change.current==='connected'){if(lastStatus!=='live')online();}
            else if(id && ['disconnected','unavailable','failed'].includes(change.current || ''))statusChanged('offline');
        };
        connection?.bind('state_change',connectionChanged);
        window.addEventListener('online',online);document.addEventListener('visibilitychange',visible);
        void start();
        return ()=> {active=false;if(hiddenTimer)clearTimeout(hiddenTimer);connection?.unbind('state_change',connectionChanged);controller.abort();void close();batches.clear();queued.length=0;syncRef.current=()=>{};window.removeEventListener('online',online);document.removeEventListener('visibilitychange',visible);};
    },[url,mode,owner]);
    return {status,sync:useCallback(()=>syncRef.current(),[])};
}
