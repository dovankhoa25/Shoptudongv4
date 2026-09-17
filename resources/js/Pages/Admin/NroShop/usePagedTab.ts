import {useState} from 'react';
import {useLiveResource} from '@/Realtime/useLiveResource';
import {base} from './shared';
import type {Paged} from './types';

export function usePagedTab<T>(path:string,errorText:string,_dataVersion=0) {
    const [filters,setFilters]=useState<Record<string,unknown>>({});
    const [query,setQuery]=useState('page=1');
    const resource=useLiveResource<Paged<T>>(`${base}${path}?${query}`,errorText);
    const rows=resource.data ?? {data:[],total:0,page:1,perPage:20};
    const apply=(next:Record<string,unknown>=filters,page=1)=> {
        setFilters(next);
        const values:Record<string,unknown>={...next,page};
        const clean=Object.entries(values).filter(([,value])=>value!==undefined && value!==null && value!=='').map(([key,value])=>[key,String(value)]);
        const params=new URLSearchParams(clean).toString();
        if(params===query)resource.reload();else setQuery(params);
    };
    return {rows,loading:resource.loading,filters,setFilters,apply,reload:resource.reload,error:resource.error,warning:resource.warning};
}
