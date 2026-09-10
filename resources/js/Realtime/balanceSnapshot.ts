type BalanceUser={id?:number|string;balance?:number;balance_revision?:number};
export function balanceEventPatch(current:BalanceUser|null|undefined,userId:number|string|undefined,payload:{balance?:unknown;balance_revision?:unknown}) {
    if(!current || userId==null || String(current.id)!==String(userId) || payload.balance==null)return null;
    const balance=Number(payload.balance),revision=Number(payload.balance_revision);
    if(!Number.isSafeInteger(balance) || !Number.isSafeInteger(revision) || revision<1 || revision<=(current.balance_revision ?? 0))return null;
    return {balance,balance_revision:revision};
}
export function mergeBalanceProfile<T extends BalanceUser>(current:T,next:Partial<T>):T {
    const result={...current,...next};
    if(next.id!=null && String(next.id)!==String(current.id))return result;
    if(current.balance_revision && (next.balance_revision ?? 0)<current.balance_revision) {
        result.balance=current.balance;result.balance_revision=current.balance_revision;
    }
    return result;
}
