// Admin/CategoryTemplates/TemplateFormModal.tsx - Template Form Modal (FormData Version)
import { Modal, Form, Input, Button, Card, Space, Divider } from "antd";
import { router } from "@inertiajs/react";
import { useToast } from "@/Components/ToastProvider";
import { useState } from "react";
import { 
    Plus, Trash2, FileText, CheckCircle, AlertCircle, 
    BookOpen, HelpCircle, MessageCircleQuestion 
} from "lucide-react";

const { TextArea } = Input;

interface IFAQ {
    question: string;
    answer: string;
}

interface ICategoryTemplate {
    id: number;
    category_id: number;
    features: string[];
    requirements: string[];
    instructions: string[];
    faq: IFAQ[];
}

interface ICategoryWithTemplate {
    id: number;
    name: string;
    status: boolean;
    category_template?: ICategoryTemplate;
}

interface IProps {
    open: boolean;
    onClose: () => void;
    category: ICategoryWithTemplate;
    onSuccess: () => void;
}

export default function TemplateFormModal({ open, onClose, category, onSuccess }: IProps) {
    const isEdit = !!category.category_template;
    const [processing, setProcessing] = useState(false);
    
    const [features, setFeatures] = useState<string[]>(
        category.category_template?.features || ['']
    );
    const [requirements, setRequirements] = useState<string[]>(
        category.category_template?.requirements || ['']
    );
    const [instructions, setInstructions] = useState<string[]>(
        category.category_template?.instructions || ['']
    );
    const [faq, setFaq] = useState<IFAQ[]>(
        category.category_template?.faq || [{ question: '', answer: '' }]
    );

    const toast = useToast();

    const handleSubmit = () => {
        // Prepare data for submission
        const filteredFeatures = features.filter(f => f.trim());
        const filteredRequirements = requirements.filter(r => r.trim());
        const filteredInstructions = instructions.filter(i => i.trim());
        const filteredFaq = faq.filter(f => f.question.trim() && f.answer.trim());

        // Validate that we have at least some content
        if (filteredFeatures.length === 0 && filteredRequirements.length === 0 && 
            filteredInstructions.length === 0 && filteredFaq.length === 0) {
            toast.error('Vui lòng nhập ít nhất một nội dung cho template!');
            return;
        }

        setProcessing(true);

        // Create FormData object
        const formData = new FormData();
        formData.append('category_id', category.id.toString());
        
        // Append arrays as individual items
        filteredFeatures.forEach((feature, index) => {
            formData.append(`features[${index}]`, feature);
        });
        
        filteredRequirements.forEach((requirement, index) => {
            formData.append(`requirements[${index}]`, requirement);
        });
        
        filteredInstructions.forEach((instruction, index) => {
            formData.append(`instructions[${index}]`, instruction);
        });
        
        filteredFaq.forEach((faqItem, index) => {
            formData.append(`faq[${index}][question]`, faqItem.question);
            formData.append(`faq[${index}][answer]`, faqItem.answer);
        });

        router.post('/admin/category-templates', formData, {
            forceFormData: true,
            onSuccess: () => {
                toast.success(`${isEdit ? 'Cập nhật' : 'Tạo'} template thành công!`);
                onSuccess();
                onClose();
                setProcessing(false);
            },
            onError: () => {
                toast.error(`${isEdit ? 'Cập nhật' : 'Tạo'} template thất bại. Vui lòng thử lại!`);
                setProcessing(false);
            }
        });
    };

    // Features handlers
    const addFeature = () => {
        setFeatures([...features, '']);
    };

    const removeFeature = (index: number) => {
        const newFeatures = features.filter((_, i) => i !== index);
        setFeatures(newFeatures);
    };

    const updateFeature = (index: number, value: string) => {
        const newFeatures = [...features];
        newFeatures[index] = value;
        setFeatures(newFeatures);
    };

    // Requirements handlers
    const addRequirement = () => {
        setRequirements([...requirements, '']);
    };

    const removeRequirement = (index: number) => {
        const newRequirements = requirements.filter((_, i) => i !== index);
        setRequirements(newRequirements);
    };

    const updateRequirement = (index: number, value: string) => {
        const newRequirements = [...requirements];
        newRequirements[index] = value;
        setRequirements(newRequirements);
    };

    // Instructions handlers
    const addInstruction = () => {
        setInstructions([...instructions, '']);
    };

    const removeInstruction = (index: number) => {
        const newInstructions = instructions.filter((_, i) => i !== index);
        setInstructions(newInstructions);
    };

    const updateInstruction = (index: number, value: string) => {
        const newInstructions = [...instructions];
        newInstructions[index] = value;
        setInstructions(newInstructions);
    };

    // FAQ handlers
    const addFaq = () => {
        setFaq([...faq, { question: '', answer: '' }]);
    };

    const removeFaq = (index: number) => {
        const newFaq = faq.filter((_, i) => i !== index);
        setFaq(newFaq);
    };

    const updateFaqQuestion = (index: number, value: string) => {
        const newFaq = [...faq];
        newFaq[index].question = value;
        setFaq(newFaq);
    };

    const updateFaqAnswer = (index: number, value: string) => {
        const newFaq = [...faq];
        newFaq[index].answer = value;
        setFaq(newFaq);
    };

    const SectionCard = ({ title, icon: Icon, color, children }: any) => (
        <Card 
            size="small" 
            title={
                <div className="flex items-center gap-2">
                    <Icon className={`w-4 h-4 ${color}`} />
                    <span>{title}</span>
                </div>
            }
            className="mb-4"
        >
            {children}
        </Card>
    );

    return (
        <Modal
            open={open}
            onCancel={onClose}
            onOk={handleSubmit}
            okText={isEdit ? "Cập nhật template" : "Tạo template"}
            cancelText="Hủy"
            centered
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-green-600 rounded-lg flex items-center justify-center">
                        <FileText className="w-4 h-4 text-white" />
                    </div>
                    {isEdit ? `Chỉnh sửa template "${category.name}"` : `Tạo template cho "${category.name}"`}
                </div>
            }
            confirmLoading={processing}
            width={900}
            style={{ top: 20 }}
        >
            <div className="max-h-[70vh] overflow-y-auto space-y-4">
                {/* Category info */}
                <div className="bg-blue-50 p-3 rounded-lg">
                    <div className="text-sm font-medium text-gray-700">Danh mục:</div>
                    <div className="text-lg font-semibold text-blue-800">{category.name}</div>
                </div>

                {/* Features Section */}
                <SectionCard 
                    title="Tính năng nổi bật" 
                    icon={CheckCircle} 
                    color="text-green-600"
                >
                    <div className="space-y-2">
                        {features.map((feature, index) => (
                            <div key={index} className="flex gap-2">
                                <Input
                                    placeholder={`Tính năng ${index + 1}...`}
                                    value={feature}
                                    onChange={(e) => updateFeature(index, e.target.value)}
                                />
                                <Button
                                    type="text"
                                    danger
                                    icon={<Trash2 className="w-4 h-4" />}
                                    onClick={() => removeFeature(index)}
                                    disabled={features.length === 1}
                                />
                            </div>
                        ))}
                        <Button 
                            type="dashed" 
                            icon={<Plus className="w-4 h-4" />}
                            onClick={addFeature}
                            className="w-full"
                        >
                            Thêm tính năng
                        </Button>
                    </div>
                </SectionCard>

                {/* Requirements Section */}
                <SectionCard 
                    title="Yêu cầu" 
                    icon={AlertCircle} 
                    color="text-orange-600"
                >
                    <div className="space-y-2">
                        {requirements.map((requirement, index) => (
                            <div key={index} className="flex gap-2">
                                <Input
                                    placeholder={`Yêu cầu ${index + 1}...`}
                                    value={requirement}
                                    onChange={(e) => updateRequirement(index, e.target.value)}
                                />
                                <Button
                                    type="text"
                                    danger
                                    icon={<Trash2 className="w-4 h-4" />}
                                    onClick={() => removeRequirement(index)}
                                    disabled={requirements.length === 1}
                                />
                            </div>
                        ))}
                        <Button 
                            type="dashed" 
                            icon={<Plus className="w-4 h-4" />}
                            onClick={addRequirement}
                            className="w-full"
                        >
                            Thêm yêu cầu
                        </Button>
                    </div>
                </SectionCard>

                {/* Instructions Section */}
                <SectionCard 
                    title="Hướng dẫn sử dụng" 
                    icon={BookOpen} 
                    color="text-blue-600"
                >
                    <div className="space-y-2">
                        {instructions.map((instruction, index) => (
                            <div key={index} className="flex gap-2">
                                <div className="bg-blue-600 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold mt-1.5 flex-shrink-0">
                                    {index + 1}
                                </div>
                                <Input
                                    placeholder={`Bước ${index + 1}...`}
                                    value={instruction}
                                    onChange={(e) => updateInstruction(index, e.target.value)}
                                />
                                <Button
                                    type="text"
                                    danger
                                    icon={<Trash2 className="w-4 h-4" />}
                                    onClick={() => removeInstruction(index)}
                                    disabled={instructions.length === 1}
                                />
                            </div>
                        ))}
                        <Button 
                            type="dashed" 
                            icon={<Plus className="w-4 h-4" />}
                            onClick={addInstruction}
                            className="w-full"
                        >
                            Thêm bước hướng dẫn
                        </Button>
                    </div>
                </SectionCard>

                {/* FAQ Section */}
                <SectionCard 
                    title="Câu hỏi thường gặp" 
                    icon={HelpCircle} 
                    color="text-purple-600"
                >
                    <div className="space-y-3">
                        {faq.map((faqItem, index) => (
                            <Card key={index} size="small" className="bg-gray-50">
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <MessageCircleQuestion className="w-4 h-4 text-purple-600" />
                                            <span className="font-medium text-sm">Câu hỏi {index + 1}</span>
                                        </div>
                                        <Button
                                            type="text"
                                            danger
                                            size="small"
                                            icon={<Trash2 className="w-3 h-3" />}
                                            onClick={() => removeFaq(index)}
                                            disabled={faq.length === 1}
                                        />
                                    </div>
                                    <Input
                                        placeholder="Nhập câu hỏi..."
                                        value={faqItem.question}
                                        onChange={(e) => updateFaqQuestion(index, e.target.value)}
                                    />
                                    <TextArea
                                        placeholder="Nhập câu trả lời..."
                                        value={faqItem.answer}
                                        onChange={(e) => updateFaqAnswer(index, e.target.value)}
                                        rows={2}
                                    />
                                </div>
                            </Card>
                        ))}
                        <Button 
                            type="dashed" 
                            icon={<Plus className="w-4 h-4" />}
                            onClick={addFaq}
                            className="w-full"
                        >
                            Thêm câu hỏi
                        </Button>
                    </div>
                </SectionCard>
            </div>
        </Modal>
    );
}