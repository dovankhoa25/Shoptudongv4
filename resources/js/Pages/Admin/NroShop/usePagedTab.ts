import {useState} from 'react';
import {useLiveView} from '@/Realtime/useLiveView';
import {base} from './shared';
import type {Paged} from './types';

export function usePagedTab<T>(path:string,errorText:string,_dataVersion=0) {
    const [rows,setRows]=useState<Paged<T>>({data:[],total:0,page:1,perPage:20});
    const [filters,setFilters]=useState<Record<string,unknown>>({});
    const [query,setQuery]=useState('page=1');
    const live=useLiveView<Paged<T>>(`${base}${path}?${query}`,setRows);
    const apply=(next:Record<string,unknown>=filters,page=1)=> {
        setFilters(next);
        const values:Record<string,unknown>={...next,page};
        const clean=Object.entries(values).filter(([,value])=>value!==undefined && value!==null && value!=='').map(([key,value])=>[key,String(value)]);
        const params=new URLSearchParams(clean).toString();
        if(params===query)live.sync();else setQuery(params);
    };
    return {rows,loading:live.status==='connecting',filters,setFilters,apply,reload:live.sync,error:live.status==='offline'?errorText:null};
}
