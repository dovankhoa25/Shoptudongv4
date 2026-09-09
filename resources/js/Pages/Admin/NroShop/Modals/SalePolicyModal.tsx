import axios from 'axios';
import { Button, Form, Input, Modal, Switch } from 'antd';
import type { FormInstance } from 'antd';
import { base } from '../shared';

const groups = [{value:'equipment',label:'Trang bị'},{value:'dragon_balls',label:'Ngọc Rồng'},{value:'upgrade_stones',label:'Đá nâng cấp'},{value:'crystals',label:'Sao pha lê'},{value:'support',label:'Hỗ trợ & sự kiện'},{value:'other',label:'Vật phẩm khác'}];
const groupDefaults: Record<string,string> = {upgrade_stones:'220, 221, 222, 223, 224',crystals:'441–447',dragon_balls:'14–20'};
const idList = (value: unknown) => String(value || '').trim().split(/[\s,;]+/).filter(Boolean);
const validIds = (parts: string[]) => parts.every(id => /^\d+$/.test(id) && Number(id) <= 100000);

export default function SalePolicyModal({
    open,
    form,
    busy,
    onClose,
    onSaved,
    run,
}: {
    open: boolean;
    form: FormInstance;
    busy: boolean;
    onClose: () => void;
    onSaved: () => void | Promise<void>;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
}) {
    return (
        <Modal
            zIndex={1200}
            title="Quyền bán & phân nhóm vật phẩm"
            width={820}
            styles={{body:{maxHeight:'72vh',overflowY:'auto'}}}
            open={open}
            onCancel={onClose}
            footer={null}
            destroyOnHidden
        >
            <p className="mb-3 text-sm">
                Áp dụng chung cho các kho và gói chưa mua. Đơn đã mua vẫn được giữ để giao. Đây là danh sách shop cho phép
                đăng, không thay thế điều kiện giao dịch trong game.
            </p>
            <Form
                form={form}
                layout="vertical"
                onFinish={v =>
                    run(async () => {
                        const parts = idList(v.ids);
                        if (!validIds(parts)) throw new Error('invalid IDs');
                        await axios.patch(`${base}/sale-policy`, {
                            enabled: !!v.enabled,
                            ids: [...new Set(parts.map(Number))],
                            groupOverrides: groups.flatMap(g => [...new Set(idList(v.groupIds?.[g.value]).map(Number))].map(id=>({id,group:g.value}))),
                        });
                        onClose();
                        await onSaved();
                    }, 'Đã lưu quyền bán và nhóm vật phẩm')
                }
            >
                <Form.Item name="enabled" label="Chỉ cho phép các ID bên dưới" valuePropName="checked">
                    <Switch />
                </Form.Item>
                <Form.Item
                    name="ids"
                    label="ID mẫu vật phẩm (template ID)"
                    extra="Ngăn cách bằng dấu phẩy, khoảng trắng hoặc xuống dòng. Bật giới hạn và để trống sẽ không cho đăng món nào."
                    rules={[
                        {
                            validator: (_, value) =>
                                validIds(idList(value))
                                    ? Promise.resolve()
                                    : Promise.reject(new Error('Nhập ID số nguyên từ 0 đến 100000.')),
                        },
                    ]}
                >
                    <Input.TextArea rows={6} placeholder="Nhập ID của các món được phép bán" />
                </Form.Item>
                <div className="mb-3 border-t border-slate-700 pt-4"><h3 className="font-semibold">Danh sách ID theo nhóm vật phẩm</h3><p className="mt-1 text-xs text-slate-500">Dán nhiều ID vào nhóm tương ứng, cách nhau bằng dấu phẩy, khoảng trắng hoặc xuống dòng. ID đã cấu hình được ưu tiên hơn phân nhóm tự động; ID không nhập vẫn phân nhóm theo dữ liệu game. Xóa ID để trả lại tự động.</p><p className="mt-1 text-xs text-slate-500">Mỗi ID chỉ thuộc một nhóm. Cấu hình nhóm không thay đổi danh sách được phép bán phía trên.</p></div>
                <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">{groups.map(group=><Form.Item key={group.value} name={['groupIds',group.value]} label={group.label} extra={groupDefaults[group.value] ? `ID mặc định: ${groupDefaults[group.value]}. Nhập thêm ID để bổ sung/chuyển vào nhóm.` : 'ID ngoài cấu hình vẫn phân nhóm tự động.'} rules={[{validator:(_,value)=>{
                    const parts=idList(value);
                    if(!validIds(parts))return Promise.reject(new Error('Nhập ID số nguyên từ 0 đến 100000.'));
                    const all=form.getFieldValue('groupIds') || {};
                    const otherIds=new Set(groups.filter(g=>g.value!==group.value).flatMap(g=>idList(all[g.value]).map(Number)));
                    const conflicts=[...new Set(parts.map(Number))].filter(id=>otherIds.has(id));
                    return conflicts.length?Promise.reject(new Error('ID đã thuộc nhóm khác: '+conflicts.join(', '))):Promise.resolve();
                }}]}><Input.TextArea rows={3} placeholder={`ID thuộc nhóm ${group.label.toLowerCase()}`} /></Form.Item>)}</div>
                <Button type="primary" htmlType="submit" loading={busy}>
                    Lưu cấu hình
                </Button>
            </Form>
        </Modal>
    );
}
