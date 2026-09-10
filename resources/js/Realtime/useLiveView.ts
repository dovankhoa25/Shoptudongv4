import {useCallback,useEffect,useRef,useState} from 'react';
import {usePage} from '@inertiajs/react';
import axios from 'axios';
import {echo} from '@laravel/echo-react';
import {applyLiveDelta, type LiveOp} from './liveDelta';

type Frame={viewId:string;revoked?:boolean;batch:string;base:number;revision:number;part:number;total:number;chunk:string};
type State<T>={revision:number;data:T};
/** Subscribe only while this view is visible. Change events carry rows/fields, never trigger a GET. */
export function useLiveView<T>(url:string|null,onData:(data:T)=>void,mode='page') {
    const owner=(usePage().props.auth as any)?.user?.id;
    const callback=useRef(onData);callback.current=onData;
    const syncRef=useRef<()=>void>(()=>{});
    const [status,setStatus]=useState<'connecting'|'live'|'offline'|'denied'>('connecting');
    useEffect(()=> {
        if(!url)return;
        const isHidden=()=>document.visibilityState==='hidden';
        let epoch=0,starting=false;
        let active=true,id:string|null=null,channel:any=null,renew:ReturnType<typeof setTimeout>|null=null;
        let state:State<T>|null=null,syncing=false,resync=false;
        const controller=new AbortController();
        const batches=new Map<string,{frame:Frame;parts:Map<number,string>;at:number;timeout:ReturnType<typeof setTimeout>}>();
        const queued:{base:number;revision:number;ops:LiveOp[]}[]=[];
        const commit=(update:{base:number;revision:number;ops:LiveOp[]})=> {
            if(!state || syncing){if(queued.length<1000)queued.push(update);else resync=true;return;}
            if(update.revision<=state.revision)return;
            if(update.base!==state.revision){resync=true;void synchronize();return;}
            try {state={revision:update.revision,data:applyLiveDelta(state.data,update.ops)};callback.current(state.data);}
            catch {resync=true;void synchronize();}
        };
        const receive=(frame:Frame)=> {
            if(!active || frame.viewId!==id)return;
            if(frame.revoked){setStatus('denied');void close();return;}
            if(!Number.isSafeInteger(frame.revision) || frame.total<1 || frame.total>2048 || frame.part<0 || frame.part>=frame.total)return;
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
                commit({base:frame.base,revision:frame.revision,ops});
            } catch {resync=true;void synchronize();}
        };
        async function synchronize() {
            if(!active || !id)return;
            if(syncing)return;syncing=true;resync=false;
            const currentId=id,currentEpoch=epoch;let success=false;
            try {
                const {data}=await axios.post<State<T>>(`/admin/live-views/${currentId}/sync`,{}, {signal:controller.signal});
                if(!active || currentEpoch!==epoch || currentId!==id)return;
                success=true;
                if(!state || data.revision>=state.revision){state=data;callback.current(data.data);}
                setStatus('live');
            } catch(error:any) {if(active && currentEpoch===epoch)setStatus([401,403].includes(error.response?.status)?'denied':'offline');if(error.response?.status===404 && currentEpoch===epoch)void close();}
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
            renew=setTimeout(async()=> {
                if(!active || !id)return;
                const currentId=id,currentEpoch=epoch;
                try {await axios.patch(`/admin/live-views/${currentId}`,{}, {signal:controller.signal});if(active && currentEpoch===epoch && currentId===id)lease();}
                catch {if(active && currentEpoch===epoch)setStatus('offline');}
            },15*60*1000);
        }
        async function close() {
            epoch++;starting=false;syncing=false;state=null;queued.length=0;
            for(const batch of batches.values())clearTimeout(batch.timeout);batches.clear();
            if(renew)clearTimeout(renew);
            if(channel){channel.stopListening('.AdminViewPatched',receive);echo().leave(`Admin.View.${id}`);channel=null;}
            if(id){const old=id;id=null;try{await axios.delete(`/admin/live-views/${old}`);}catch{/* Server lease expires if disconnect prevents cleanup. */}}
        }
        async function start() {
            if(!active || starting || id || isHidden())return;
            starting=true;const currentEpoch=epoch;
            setStatus('connecting');
            try {
                const {data}=await axios.post<{id:string;channel:string}>('/admin/live-views',{url,mode},{signal:controller.signal});
                if(!active || currentEpoch!==epoch || isHidden()){void axios.delete(`/admin/live-views/${data.id}`).catch(()=>{});return;}
                id=data.id;state=null;channel=echo().private(data.channel);
                channel.listen('.AdminViewPatched',receive);
                channel.on('pusher:subscription_succeeded',synchronize);
                channel.error(()=>active && setStatus('offline'));
                if(channel.subscription?.subscribed)void synchronize();
                lease();
            } catch(error:any){if(active && currentEpoch===epoch)setStatus([401,403].includes(error.response?.status)?'denied':'offline');}
            finally {if(currentEpoch===epoch)starting=false;}
        }
        const refresh=()=>{if(id)void synchronize();else void start();};syncRef.current=refresh;
        const visible=()=>{if(isHidden())void close();else refresh();};
        const connection=(echo() as any).connector?.pusher?.connection;
        const disconnected=(change:{current?:string})=>{if(active && id && ['disconnected','unavailable','failed'].includes(change.current || ''))setStatus('offline');};
        connection?.bind('state_change',disconnected);
        window.addEventListener('online',refresh);document.addEventListener('visibilitychange',visible);
        void start();
        return ()=> {active=false;connection?.unbind('state_change',disconnected);controller.abort();void close();batches.clear();queued.length=0;syncRef.current=()=>{};window.removeEventListener('online',refresh);document.removeEventListener('visibilitychange',visible);};
    },[url,mode,owner]);
    return {status,sync:useCallback(()=>syncRef.current(),[])};
}
