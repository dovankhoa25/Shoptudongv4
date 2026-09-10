export type LiveOp = {op: 'set'|'remove'|'rows';path:(string|number)[];value?:any;order?: (string|number)[];rows?:any[]};
export function applyLiveDelta<T>(current:T,ops:LiveOp[]):T {
    let result:any=current;
    for(const op of ops) {
        if(op.path.some(key=>['__proto__','prototype','constructor'].includes(String(key))))throw new Error('Invalid live path');
        const change=(old:any,depth:number):any=> {
            if(depth===op.path.length) {
                if(op.op==='set')return op.value;
                if(op.op==='remove')return undefined;
                const known=new Map((Array.isArray(old)?old:[]).map((row:any)=>[String(row.id),row]));
                for(const row of op.rows || [])known.set(String(row.id),row);
                return (op.order || []).map(id=>{if(!known.has(String(id)))throw new Error('Live row gap');return known.get(String(id));});
            }
            const key=op.path[depth];const next=Array.isArray(old)?[...old]:{...(old || {})};
            if(op.op==='remove' && depth===op.path.length-1)delete next[key as any];
            else next[key as any]=change(old?.[key],depth+1);
            return next;
        };
        result=change(result,0);
    }
    return result;
}
