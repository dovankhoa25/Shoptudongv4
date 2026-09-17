import React,{useEffect,useState} from 'react';
import {router,usePage} from '@inertiajs/react';
import {useLiveView} from '@/Realtime/useLiveView';
import type {PageProps} from '@/types';

const roots=['/admin/orders','/admin/imports','/admin/gem-orders','/admin/services/orders','/admin/services/orders/receiver',
    '/admin/games/accounts/history','/admin/withdrawals','/admin/cards','/admin/deposits','/admin/carot-recharges',
    '/admin/transactions','/admin/users','/admin/dashboard','/admin/analytics','/admin/nro-shop'];
export default function AdminRealtimeProvider({children}:{children:React.ReactNode}) {
    const {url,props}=usePage<PageProps>();const path=url.split('?')[0];
    const eligible=roots.includes(path) || /^\/admin\/(orders|imports|gem-orders)\/\d+$/.test(path);
    const defaultMode=path==='/admin/nro-shop' && (props.capabilities as {accounts?:boolean}|undefined)?.accounts===false?'summary':'page';
    const [selection,setSelection]=useState({path,mode:defaultMode});
    // Reset before committing a subscription for the next page, including browser Back.
    if(selection.path!==path)setSelection({path,mode:defaultMode});
    const mode=selection.path===path?selection.mode:defaultMode;
    const live=useLiveView<Record<string,unknown>>(eligible && props.auth.user?.id?url:null,data=> {
        router.replace({props:current=>({...current,...data}),preserveState:true,preserveScroll:true});
    },path==='/admin/nro-shop'?mode:'page');
    useEffect(()=> {
        const tab=(event:Event)=>setSelection({path,mode:(event as CustomEvent).detail==='accounts'?'page':'summary'});
        const sync=()=>{if(live.status==='live')live.sync();else router.reload();};window.addEventListener('admin:nro-tab',tab);window.addEventListener('admin:live-sync',sync);
        const afterWrite=()=>{if(live.status!=='live')router.reload();};window.addEventListener('admin:refresh-if-offline',afterWrite);
        return ()=>{window.removeEventListener('admin:nro-tab',tab);window.removeEventListener('admin:live-sync',sync);window.removeEventListener('admin:refresh-if-offline',afterWrite);};
    },[path,live.sync,live.status]);
    return <>{eligible && ['offline','denied'].includes(live.status) && <div role="status" className="px-4 py-1 text-xs text-amber-600">Cập nhật trực tiếp đang gián đoạn. <button className="underline mr-3" onClick={()=>router.reload()}>Làm mới dữ liệu</button><button className="underline" onClick={live.sync}>Kết nối lại</button></div>}{children}</>;
}
