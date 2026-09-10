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
    const [mode,setMode]=useState('page');
    useEffect(()=>setMode('page'),[path]);
    const live=useLiveView<Record<string,unknown>>(eligible && props.auth.user?.id?url:null,data=> {
        router.replace({props:current=>({...current,...data}),preserveState:true,preserveScroll:true});
    },path==='/admin/nro-shop'?mode:'page');
    useEffect(()=> {
        const tab=(event:Event)=>setMode((event as CustomEvent).detail==='accounts'?'page':'summary');
        const sync=()=>live.sync();window.addEventListener('admin:nro-tab',tab);window.addEventListener('admin:live-sync',sync);
        return ()=>{window.removeEventListener('admin:nro-tab',tab);window.removeEventListener('admin:live-sync',sync);};
    },[live.sync]);
    return <>{eligible && live.status==='offline' && <div role="status" className="px-4 py-1 text-xs text-amber-600">Mất cập nhật trực tiếp. <button className="underline" onClick={live.sync}>Kết nối lại</button></div>}{eligible && live.status==='denied' && <div role="alert" className="px-4 py-1 text-xs text-red-600">Phiên cập nhật đã hết hạn hoặc quyền xem đã thay đổi.</div>}{children}</>;
}
