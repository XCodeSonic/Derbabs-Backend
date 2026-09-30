// src/components/MenuManagement.jsx - COMPLETE FIXED
import React, { useMemo, useState } from 'react';
import {
    Card,
    Table,
    Button,
    Space,
    Input,
    Select,
    Modal,
    Tabs,
    Tag,
    message,
    Divider,
    Tooltip,
    Typography,
    Row,
    Col,
    Alert,
    DatePicker,
    Badge,
    Empty,
    Form,
    InputNumber,
    Upload,
    Image,
    ConfigProvider,
    Switch,
    Progress,
    Steps,
    Descriptions,
    Rate,
    Spin,
    Skeleton,
    Pagination
} from 'antd';
import {
    FaPizzaSlice,
    FaHamburger,
    FaUtensils,
    FaFish,
    FaIceCream,
    FaCoffee,
    FaBeer,
    FaAppleAlt,
    FaBreadSlice,
    FaCheese,
    FaEgg,
    FaCarrot,
    FaDrumstickBite,
    FaPepperHot,
    FaBirthdayCake,
    FaCookie,
    FaWineGlassAlt,
    FaMugHot,
} from 'react-icons/fa';
import {
    GiNoodles,
    GiChopsticks,
    GiSandwich,
} from 'react-icons/gi';
import {
    MdSoupKitchen,
    MdFastfood,
    MdBreakfastDining,
    MdLocalDrink,
    MdRestaurant,
    MdDinnerDining,
} from 'react-icons/md';
import {
    PlusOutlined,
    EditOutlined,
    DeleteOutlined,
    ReloadOutlined,
    PrinterOutlined,
    ExportOutlined,
    TagOutlined,
    AppstoreOutlined,
    FireOutlined,
    CheckCircleOutlined,
    MenuOutlined,
    GiftOutlined,
    TeamOutlined,
    CalendarOutlined,
    BoxPlotOutlined,
    SunOutlined,
    MoonOutlined,
    ForkOutlined,
    EyeOutlined,
    InfoCircleOutlined,
    UploadOutlined,
    StarFilled,
    LeftOutlined,
    RightOutlined,
    CloseOutlined,
    SaveOutlined,
    ShoppingOutlined,
    DollarOutlined,
    ClockCircleOutlined,
} from '@ant-design/icons';
import {
    useMenuItems,
    useCreateMenuItem,
    useUpdateMenuItem,
    useDeleteMenuItem,
    useCategories,
    useCreateCategory,
    useUpdateCategory,
    useDeleteCategory,
    usePackages,
    useCreatePackage,
    useUpdatePackage,
    useDeletePackage,
    usePromotions,
    useCreatePromotion,
    useUpdatePromotion,
    useDeletePromotion,
    useIngredients,
    useMenuStatistics,
    useToggleMenuItemFeatured,
} from '../../../hooks/useMenuQueries';
import dayjs from 'dayjs';
import '../styles/menu.css';

const { Text } = Typography;
const { Option } = Select;
const { TabPane } = Tabs;
const { TextArea } = Input;
const { Search } = Input;

// ============================================================
// ICON HELPERS
// ============================================================
const PROFESSIONAL_ICONS = [
    { name: 'Pizza', icon: <FaPizzaSlice size={28} />, value: 'FaPizzaSlice', category: 'Italian' },
    { name: 'Burger', icon: <FaHamburger size={28} />, value: 'FaHamburger', category: 'Fast Food' },
    { name: 'Noodles', icon: <GiNoodles size={28} />, value: 'GiNoodles', category: 'Asian' },
    { name: 'Soup', icon: <MdSoupKitchen size={28} />, value: 'MdSoupKitchen', category: 'Soups' },
    { name: 'Pasta', icon: <GiChopsticks size={28} />, value: 'GiChopsticks', category: 'Italian' },
    { name: 'Sandwich', icon: <GiSandwich size={28} />, value: 'GiSandwich', category: 'Sandwiches' },
    { name: 'Breakfast', icon: <MdBreakfastDining size={28} />, value: 'MdBreakfastDining', category: 'Breakfast' },
    { name: 'Chicken', icon: <FaDrumstickBite size={28} />, value: 'FaDrumstickBite', category: 'Poultry' },
    { name: 'Fish', icon: <FaFish size={28} />, value: 'FaFish', category: 'Seafood' },
    { name: 'Vegetarian', icon: <FaCarrot size={28} />, value: 'FaCarrot', category: 'Vegetarian' },
    { name: 'Dessert', icon: <FaBirthdayCake size={28} />, value: 'FaBirthdayCake', category: 'Desserts' },
    { name: 'Ice Cream', icon: <FaIceCream size={28} />, value: 'FaIceCream', category: 'Desserts' },
    { name: 'Beverage', icon: <MdLocalDrink size={28} />, value: 'MdLocalDrink', category: 'Beverages' },
    { name: 'Coffee', icon: <FaCoffee size={28} />, value: 'FaCoffee', category: 'Beverages' },
    { name: 'Hot Drink', icon: <FaMugHot size={28} />, value: 'FaMugHot', category: 'Beverages' },
    { name: 'Beer', icon: <FaBeer size={28} />, value: 'FaBeer', category: 'Alcoholic' },
    { name: 'Wine', icon: <FaWineGlassAlt size={28} />, value: 'FaWineGlassAlt', category: 'Alcoholic' },
    { name: 'Spicy', icon: <FaPepperHot size={28} />, value: 'FaPepperHot', category: 'Special' },
    { name: 'Cheese', icon: <FaCheese size={28} />, value: 'FaCheese', category: 'Dairy' },
    { name: 'Snacks', icon: <MdFastfood size={28} />, value: 'MdFastfood', category: 'Snacks' },
    { name: 'Bakery', icon: <FaBreadSlice size={28} />, value: 'FaBreadSlice', category: 'Bakery' },
    { name: 'Fruit', icon: <FaAppleAlt size={28} />, value: 'FaAppleAlt', category: 'Healthy' },
    { name: 'Eggs', icon: <FaEgg size={28} />, value: 'FaEgg', category: 'Breakfast' },
    { name: 'Utensils', icon: <FaUtensils size={28} />, value: 'FaUtensils', category: 'General' },
    { name: 'Cookie', icon: <FaCookie size={28} />, value: 'FaCookie', category: 'Desserts' },
    { name: 'Restaurant', icon: <MdRestaurant size={28} />, value: 'MdRestaurant', category: 'General' },
    { name: 'Dinner', icon: <MdDinnerDining size={28} />, value: 'MdDinnerDining', category: 'Main Course' },
];

const renderIcon = (iconValue) => {
    if (!iconValue) return <ForkOutlined style={{ fontSize: '24px' }} />;

    const iconMap = {
        FaPizzaSlice: <FaPizzaSlice size={24} />,
        FaHamburger: <FaHamburger size={24} />,
        GiNoodles: <GiNoodles size={24} />,
        MdSoupKitchen: <MdSoupKitchen size={24} />,
        GiChopsticks: <GiChopsticks size={24} />,
        GiSandwich: <GiSandwich size={24} />,
        MdBreakfastDining: <MdBreakfastDining size={24} />,
        FaDrumstickBite: <FaDrumstickBite size={24} />,
        FaFish: <FaFish size={24} />,
        FaCarrot: <FaCarrot size={24} />,
        FaBirthdayCake: <FaBirthdayCake size={24} />,
        FaIceCream: <FaIceCream size={24} />,
        MdLocalDrink: <MdLocalDrink size={24} />,
        FaCoffee: <FaCoffee size={24} />,
        FaMugHot: <FaMugHot size={24} />,
        FaBeer: <FaBeer size={24} />,
        FaWineGlassAlt: <FaWineGlassAlt size={24} />,
        FaPepperHot: <FaPepperHot size={24} />,
        FaCheese: <FaCheese size={24} />,
        MdFastfood: <MdFastfood size={24} />,
        FaBreadSlice: <FaBreadSlice size={24} />,
        FaAppleAlt: <FaAppleAlt size={24} />,
        FaEgg: <FaEgg size={24} />,
        FaUtensils: <FaUtensils size={24} />,
        FaCookie: <FaCookie size={24} />,
        MdRestaurant: <MdRestaurant size={24} />,
        MdDinnerDining: <MdDinnerDining size={24} />,
    };

    return iconMap[iconValue] || <ForkOutlined style={{ fontSize: '24px' }} />;
};

const ProfessionalIconPicker = ({ value, onChange }) => {
    const [visible, setVisible] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');

    const filteredIcons = PROFESSIONAL_ICONS.filter((icon) => (
        icon.name.toLowerCase().includes(searchTerm.toLowerCase())
        || icon.category.toLowerCase().includes(searchTerm.toLowerCase())
    ));

    const selectedIcon = PROFESSIONAL_ICONS.find((icon) => icon.value === value);

    return (
        <div>
            <div
                onClick={() => setVisible(true)}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '10px 14px',
                    border: '1px solid #d9d9d9',
                    borderRadius: '10px',
                    cursor: 'pointer',
                    backgroundColor: '#fafafa',
                    transition: 'all 0.3s',
                }}
            >
                {value && selectedIcon ? (
                    <>
                        <span style={{ marginRight: '10px', fontSize: '24px' }}>{selectedIcon.icon}</span>
                        <span style={{ color: '#1e293b', fontWeight: 500 }}>{selectedIcon.name}</span>
                    </>
                ) : (
                    <span style={{ color: '#94a3b8' }}>Click to select an icon</span>
                )}
            </div>

            <Modal
                title="Select Category Icon"
                open={visible}
                onCancel={() => setVisible(false)}
                footer={<Button type="primary" onClick={() => setVisible(false)}>Close</Button>}
                width={750}
                maskClosable={false}
            >
                <Input.Search
                    placeholder="Search icons..."
                    onChange={(event) => setSearchTerm(event.target.value)}
                    style={{ marginBottom: 16 }}
                    allowClear
                />
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(5, 1fr)',
                        gap: '12px',
                        maxHeight: '450px',
                        overflowY: 'auto',
                        padding: '8px',
                    }}
                >
                    {filteredIcons.map((icon) => (
                        <div
                            key={icon.value}
                            onClick={() => {
                                onChange?.(icon.value);
                                setVisible(false);
                            }}
                            style={{
                                display: 'flex',
                                flexDirection: 'column',
                                alignItems: 'center',
                                padding: '16px 8px',
                                borderRadius: '12px',
                                cursor: 'pointer',
                                border: value === icon.value ? '2px solid #1a7ab5' : '1px solid #e8e8e8',
                                backgroundColor: value === icon.value ? '#e6f7ff' : '#ffffff',
                                transition: 'all 0.3s',
                            }}
                        >
                            <span style={{ fontSize: '32px', marginBottom: '8px' }}>{icon.icon}</span>
                            <span style={{ fontSize: '13px', fontWeight: value === icon.value ? 600 : 400, color: '#1e293b' }}>{icon.name}</span>
                            <span style={{ fontSize: '11px', color: '#94a3b8', marginTop: '4px' }}>{icon.category}</span>
                        </div>
                    ))}
                </div>
            </Modal>
        </div>
    );
};

// ============================================================
// HELPERS
// ============================================================
const currency = (value) => `₱${Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;

const csvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

// ============================================================
// PAGINATION HELPERS
// ============================================================
const renderPaginationItem = (_, type, originalElement) => {
    if (type === 'prev') {
        return (
            <Button className="mm-pagination-navigation-button" size="small" icon={<LeftOutlined />}>
                Previous
            </Button>
        );
    }
    if (type === 'next') {
        return (
            <Button className="mm-pagination-navigation-button" size="small">
                Next <RightOutlined />
            </Button>
        );
    }
    return originalElement;
};

const renderEmptyPaginationFooter = (label) => {
    return (
        <div className="mm-empty-pagination-footer">
            <span className="mm-empty-pagination-total">Total 0 {label}</span>
            <div className="mm-empty-pagination-controls">
                <Button className="mm-pagination-navigation-button" size="small" icon={<LeftOutlined />} disabled>
                    Previous
                </Button>
                <button type="button" className="mm-empty-pagination-current-page" disabled>1</button>
                <Button className="mm-pagination-navigation-button" size="small" disabled>
                    Next <RightOutlined />
                </Button>
            </div>
        </div>
    );
};

// ============================================================
// MAIN COMPONENT
// ============================================================
const MenuManagement = () => {
    const [menuFilters, setMenuFilters] = useState({ search: '', category_id: null });
    const [packageFilters, setPackageFilters] = useState({ search: '', is_active: null });
    const [categoryFilters, setCategoryFilters] = useState({ search: '', is_active: null });
    const [promotionFilters, setPromotionFilters] = useState({ search: '', is_active: null });
    const [activeMainTab, setActiveMainTab] = useState('menus');
    const [isDarkMode, setIsDarkMode] = useState(false);

    const [menuModalVisible, setMenuModalVisible] = useState(false);
    const [packageModalVisible, setPackageModalVisible] = useState(false);
    const [categoryModalVisible, setCategoryModalVisible] = useState(false);
    const [promoModalVisible, setPromoModalVisible] = useState(false);
    const [recipeModalVisible, setRecipeModalVisible] = useState(false);
    const [selectedItem, setSelectedItem] = useState(null);
    const [selectedPackageItem, setSelectedPackageItem] = useState(null);
    const [selectedMenuItems, setSelectedMenuItems] = useState([]);
    const [recipeIngredients, setRecipeIngredients] = useState([]);
    const [editingCategory, setEditingCategory] = useState(null);
    const [editingPromotion, setEditingPromotion] = useState(null);
    const [modalCurrentStep, setModalCurrentStep] = useState(0);
    const [packageCurrentStep, setPackageCurrentStep] = useState(0);
    const [menuFileList, setMenuFileList] = useState([]);
    const [packageFileList, setPackageFileList] = useState([]);

    const [menuForm] = Form.useForm();
    const [packageForm] = Form.useForm();
    const [categoryForm] = Form.useForm();
    const [promoForm] = Form.useForm();

    // The UI paginates locally so all records are fetched once for filters, counts and package selection.
    const menuQuery = useMenuItems({ page: 1, per_page: 500 });
    const categoryQuery = useCategories({ page: 1, per_page: 500 });
    const packageQuery = usePackages({ page: 1, per_page: 500 });
    const promotionQuery = usePromotions({ page: 1, per_page: 500 });
    const ingredientQuery = useIngredients({ page: 1, per_page: 500 });
    const statisticsQuery = useMenuStatistics();

    const createMenuItem = useCreateMenuItem();
    const updateMenuItem = useUpdateMenuItem();
    const deleteMenuItem = useDeleteMenuItem();
    const toggleFeatured = useToggleMenuItemFeatured();
    const createCategory = useCreateCategory();
    const updateCategory = useUpdateCategory();
    const deleteCategory = useDeleteCategory();
    const createPackage = useCreatePackage();
    const updatePackage = useUpdatePackage();
    const deletePackage = useDeletePackage();
    const createPromotion = useCreatePromotion();
    const updatePromotion = useUpdatePromotion();
    const deletePromotion = useDeletePromotion();

    // Get data from cache
    const menus = menuQuery.data?.data || [];
    const categories = categoryQuery.data?.data || [];
    const packages = packageQuery.data?.data || [];
    const promotions = promotionQuery.data?.data || [];
    const ingredients = ingredientQuery.data?.data || [];

    const ingredientById = useMemo(() => new Map(
        ingredients.map((ingredient) => [Number(ingredient.id), ingredient]),
    ), [ingredients]);

    const calculateTotalRecipeCost = () => recipeIngredients.reduce((sum, ingredientRow) => {
        const ingredient = ingredientById.get(Number(ingredientRow.ingredient_id));
        return sum + Number(ingredientRow.quantity_per_pax || 0) * Number(ingredient?.unit_cost || 0);
    }, 0);

    const calculateProfitMargin = () => {
        const price = Number(menuForm.getFieldValue('price') || 0);
        const cost = calculateTotalRecipeCost();
        if (price <= 0) return 0;
        return ((price - cost) / price) * 100;
    };

    const closeMenuModal = () => {
        setMenuModalVisible(false);
        setSelectedItem(null);
        setRecipeIngredients([]);
        setMenuFileList([]);
        menuForm.resetFields();
        setModalCurrentStep(0);
    };

    const closePackageModal = () => {
        setPackageModalVisible(false);
        setSelectedPackageItem(null);
        setSelectedMenuItems([]);
        setPackageFileList([]);
        packageForm.resetFields();
        setPackageCurrentStep(0);
    };

    const handleRefresh = async () => {
        try {
            await Promise.all([
                menuQuery.refetch(),
                categoryQuery.refetch(),
                packageQuery.refetch(),
                promotionQuery.refetch(),
                ingredientQuery.refetch(),
                statisticsQuery.refetch(),
            ]);
            message.success('All menu-management data refreshed successfully');
        } catch {
            message.error('Failed to refresh menu-management data');
        }
    };

    const handlePrint = () => window.print();

    const handleExport = () => {
        const rows = [
            ['ID', 'Name', 'Category', 'Description', 'Price', 'Preparation Minutes', 'Available', 'Featured'],
            ...menus.map((item) => [
                item.id,
                item.name,
                item.category,
                item.description,
                item.price,
                item.prep_time_minutes,
                item.is_available ? 'Yes' : 'No',
                item.is_popular ? 'Yes' : 'No',
            ]),
        ];
        const csv = rows.map((row) => row.map(csvCell).join(',')).join('\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `menu-items-${dayjs().format('YYYY-MM-DD')}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    };

    const handleToggleFeatured = async (record) => {
        try {
            await toggleFeatured.mutateAsync(record.id);
        } catch {
            // The mutation hook displays the API message.
        }
    };

    const handleAddMenu = () => {
        closeMenuModal();
        menuForm.setFieldsValue({
            prep_time_minutes: 15,
            serving_size: 1,
            is_available: true,
            is_popular: false,
            is_vegetarian: false,
            is_vegan: false,
            is_gluten_free: false,
            is_halal: false,
        });
        setMenuModalVisible(true);
    };

    const handleEditMenu = (record) => {
        setSelectedItem(record);
        setRecipeIngredients((record.recipe_ingredients || []).map((row) => ({
            ...row,
            id: row.id || row.recipe_ingredient_id || `${row.ingredient_id}-${Date.now()}`,
            ingredient_id: Number(row.ingredient_id),
            quantity_per_pax: Number(row.quantity_per_pax || 0),
            unit: row.unit || row.ingredient?.unit || 'kg',
        })));
        setMenuFileList(record.image_url ? [{
            uid: 'current-image',
            name: 'Current menu image',
            status: 'done',
            url: record.image_url,
        }] : []);
        setModalCurrentStep(0);
        menuForm.resetFields();
        menuForm.setFieldsValue({
            name: record.name,
            category_id: record.category_id,
            description: record.description || '',
            price: Number(record.price || 0),
            prep_time_minutes: Number(record.prep_time_minutes || 0),
            serving_size: Number(record.serving_size || 1),
            is_available: record.is_available !== false,
            is_popular: Boolean(record.is_popular),
            is_vegetarian: Boolean(record.is_vegetarian),
            is_vegan: Boolean(record.is_vegan),
            is_gluten_free: Boolean(record.is_gluten_free),
            is_halal: Boolean(record.is_halal),
            allergens: record.allergens
                ? String(record.allergens).split(',').map((value) => value.trim()).filter(Boolean)
                : [],
            nutritional_info: record.nutritional_info || '',
            ingredients_list: record.ingredients_list || '',
        });
        setMenuModalVisible(true);
    };

    const handleDeleteMenu = (record) => {
        Modal.confirm({
            title: 'Delete Menu Item',
            content: `Are you sure you want to delete "${record.name}"?`,
            okText: 'Yes, Delete',
            okType: 'danger',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await deleteMenuItem.mutateAsync(record.id);
                } catch {
                    // The mutation hook displays the API message.
                }
            },
        });
    };

    const handleAddRecipeIngredient = () => {
        setRecipeIngredients((rows) => [...rows, {
            id: `new-${Date.now()}`,
            ingredient_id: null,
            quantity_per_pax: 0.001,
            unit: 'kg',
        }]);
    };

    const handleUpdateRecipeIngredient = (id, field, value) => {
        setRecipeIngredients((rows) => rows.map((row) => {
            if (row.id !== id) return row;
            if (field !== 'ingredient_id') return { ...row, [field]: value };
            const ingredient = ingredientById.get(Number(value));
            return {
                ...row,
                ingredient_id: Number(value),
                unit: ingredient?.unit || row.unit || 'kg',
            };
        }));
    };

    const handleRemoveRecipeIngredient = (id) => {
        setRecipeIngredients((rows) => rows.filter((row) => row.id !== id));
    };

    const appendBoolean = (formData, key, value) => {
        formData.append(key, value ? '1' : '0');
    };

    const handleSaveMenu = async () => {
        try {
            const values = await menuForm.validateFields();
            const formData = new FormData();
            const recipePayload = recipeIngredients
                .filter((row) => row.ingredient_id && Number(row.quantity_per_pax) > 0)
                .map((row) => ({
                    ingredient_id: Number(row.ingredient_id),
                    quantity_per_pax: Number(row.quantity_per_pax),
                    unit: row.unit || ingredientById.get(Number(row.ingredient_id))?.unit || 'kg',
                }));

            formData.append('name', values.name.trim());
            formData.append('category_id', String(values.category_id));
            formData.append('description', values.description || '');
            formData.append('price', String(values.price));
            formData.append('cost_to_make', String(calculateTotalRecipeCost()));
            formData.append('prep_time_minutes', String(values.prep_time_minutes ?? 0));
            formData.append('serving_size', String(values.serving_size ?? 1));
            formData.append('allergens', Array.isArray(values.allergens) ? values.allergens.join(', ') : (values.allergens || ''));
            formData.append('nutritional_info', values.nutritional_info || '');
            formData.append('ingredients_list', values.ingredients_list || '');
            formData.append('ingredients', JSON.stringify(recipePayload));
            appendBoolean(formData, 'is_available', values.is_available !== false);
            appendBoolean(formData, 'is_popular', values.is_popular);
            appendBoolean(formData, 'is_vegetarian', values.is_vegetarian);
            appendBoolean(formData, 'is_vegan', values.is_vegan);
            appendBoolean(formData, 'is_gluten_free', values.is_gluten_free);
            appendBoolean(formData, 'is_halal', values.is_halal);

            const newImage = menuFileList.find((file) => file.originFileObj);
            if (newImage?.originFileObj) formData.append('image', newImage.originFileObj);

            if (selectedItem) {
                await updateMenuItem.mutateAsync({ id: selectedItem.id, data: formData });
            } else {
                await createMenuItem.mutateAsync(formData);
            }

            closeMenuModal();
        } catch {
            // Ant Design or the mutation hook displays the validation/API message.
        }
    };

    const handleViewRecipe = (record) => {
        setSelectedItem(record);
        setRecipeModalVisible(true);
    };

    const handleAddPackage = () => {
        closePackageModal();
        packageForm.setFieldsValue({
            min_pax: 1,
            max_pax: 100,
            price_per_additional_pax: 0,
            default_duration_hours: 4,
            sort_order: 0,
            is_active: true,
            is_featured: false,
        });
        setPackageModalVisible(true);
    };

    const handleEditPackage = (record) => {
        setSelectedPackageItem(record);
        setSelectedMenuItems((record.menu_items || record.items || []).map((item) => ({
            ...item,
            id: item.id || item.menu_item_id,
            menu_item_id: item.menu_item_id || item.id,
            quantity: Number(item.quantity_per_pax || item.quantity || 1),
            quantity_per_pax: Number(item.quantity_per_pax || item.quantity || 1),
        })));
        setPackageFileList(record.image_url ? [{
            uid: 'current-package-image',
            name: 'Current package image',
            status: 'done',
            url: record.image_url,
        }] : []);
        packageForm.resetFields();
        packageForm.setFieldsValue({
            name: record.name,
            description: record.description || '',
            min_pax: Number(record.min_pax || 1),
            max_pax: Number(record.max_pax || 1),
            base_price_per_pax: Number(record.base_price_per_pax || 0),
            price_per_additional_pax: Number(record.price_per_additional_pax || 0),
            default_duration_hours: Number(record.default_duration_hours || 4),
            is_active: record.is_active !== false,
            is_featured: Boolean(record.is_featured),
            sort_order: Number(record.sort_order || 0),
            inclusions: record.inclusions || [],
            exclusions: record.exclusions || [],
        });
        setPackageCurrentStep(0);
        setPackageModalVisible(true);
    };

    const handleDeletePackage = (record) => {
        Modal.confirm({
            title: 'Delete Package',
            content: `Are you sure you want to delete "${record.name}"?`,
            okText: 'Delete',
            okType: 'danger',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await deletePackage.mutateAsync(record.id);
                } catch {
                    // The mutation hook displays the API message.
                }
            },
        });
    };

    const handleAddMenuItemToPackage = (record) => {
        if (selectedMenuItems.some((item) => Number(item.menu_item_id || item.id) === Number(record.id))) {
            message.warning('Menu item is already included in this package');
            return;
        }
        setSelectedMenuItems((items) => [...items, {
            ...record,
            id: record.id,
            menu_item_id: record.id,
            quantity: 1,
            quantity_per_pax: 1,
            is_optional: false,
            is_replaceable: false,
            additional_cost: 0,
        }]);
    };

    const handleRemoveMenuItemFromPackage = (menuId) => {
        setSelectedMenuItems((items) => items.filter((item) => Number(item.menu_item_id || item.id) !== Number(menuId)));
    };

    const handleUpdateMenuItemQuantity = (menuId, quantity) => {
        setSelectedMenuItems((items) => items.map((item) => (
            Number(item.menu_item_id || item.id) === Number(menuId)
                ? { ...item, quantity: Number(quantity || 1), quantity_per_pax: Number(quantity || 1) }
                : item
        )));
    };

    const handleSavePackage = async () => {
        try {
            const values = await packageForm.validateFields();
            if (Number(values.max_pax) < Number(values.min_pax)) {
                message.error('Maximum guests must be greater than or equal to minimum guests');
                return;
            }

            const formData = new FormData();
            const menuItemsPayload = selectedMenuItems.map((item) => ({
                menu_item_id: Number(item.menu_item_id || item.id),
                quantity_per_pax: Number(item.quantity_per_pax || item.quantity || 1),
                is_optional: Boolean(item.is_optional || false),
                is_replaceable: Boolean(item.is_replaceable || false),
                additional_cost: Number(item.additional_cost || 0),
            }));

            formData.append('name', values.name.trim());
            formData.append('description', values.description || '');
            formData.append('base_price_per_pax', String(values.base_price_per_pax));
            formData.append('min_pax', String(values.min_pax));
            formData.append('max_pax', String(values.max_pax));
            formData.append('price_per_additional_pax', String(values.price_per_additional_pax || 0));
            formData.append('default_duration_hours', String(values.default_duration_hours || 4));
            formData.append('sort_order', String(values.sort_order || 0));
            formData.append('is_active', values.is_active !== false ? '1' : '0');
            formData.append('is_featured', values.is_featured ? '1' : '0');
            formData.append('inclusions', JSON.stringify(values.inclusions || []));
            formData.append('exclusions', JSON.stringify(values.exclusions || []));
            formData.append('menu_items', JSON.stringify(menuItemsPayload));

            const newImage = packageFileList.find((file) => file.originFileObj);
            if (newImage?.originFileObj) {
                formData.append('image', newImage.originFileObj);
            }

            if (selectedPackageItem) {
                await updatePackage.mutateAsync({ id: selectedPackageItem.id, data: formData });
            } else {
                await createPackage.mutateAsync(formData);
            }
            closePackageModal();
        } catch (error) {
            console.error('Save package error:', error);
        }
    };

    const handleAddCategory = () => {
        setEditingCategory(null);
        categoryForm.resetFields();
        categoryForm.setFieldsValue({ display_order: 0, is_active: true });
        setCategoryModalVisible(true);
    };

    const handleEditCategory = (record) => {
        setEditingCategory(record);
        categoryForm.resetFields();
        categoryForm.setFieldsValue({
            name: record.name,
            description: record.description || '',
            icon: record.icon || null,
            display_order: Number(record.display_order || 0),
            is_active: record.is_active !== false,
        });
        setCategoryModalVisible(true);
    };

    const handleSaveCategory = async (values) => {
        const payload = {
            name: values.name.trim(),
            description: values.description || '',
            icon: values.icon || null,
            display_order: Number(values.display_order || 0),
            is_active: values.is_active !== false,
        };
        try {
            if (editingCategory) {
                await updateCategory.mutateAsync({ id: editingCategory.id, data: payload });
            } else {
                await createCategory.mutateAsync(payload);
            }
            setCategoryModalVisible(false);
            setEditingCategory(null);
            categoryForm.resetFields();
        } catch {
            // The mutation hook displays the API message.
        }
    };

    const handleDeleteCategory = (record) => {
        Modal.confirm({
            title: 'Delete Category',
            content: `Delete "${record.name}"? Categories that still contain menu items cannot be deleted.`,
            okText: 'Yes, Delete',
            okType: 'danger',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await deleteCategory.mutateAsync(record.id);
                } catch {
                    // The mutation hook displays the API message.
                }
            },
        });
    };

    const handleAddPromotion = () => {
        setEditingPromotion(null);
        promoForm.resetFields();
        promoForm.setFieldsValue({ discount_type: 'percentage', is_active: true });
        setPromoModalVisible(true);
    };

    const handleEditPromotion = (record) => {
        setEditingPromotion(record);
        promoForm.resetFields();
        promoForm.setFieldsValue({
            name: record.name,
            code: record.code,
            description: record.description || '',
            discount_type: record.discount_type,
            discount_value: Number(record.discount_value || 0),
            start_date: record.start_date ? dayjs(record.start_date) : null,
            end_date: record.end_date ? dayjs(record.end_date) : null,
            is_active: record.is_active !== false,
        });
        setPromoModalVisible(true);
    };

    const handleSavePromotion = async (values) => {
        const payload = {
            name: values.name.trim(),
            code: values.code.trim().toUpperCase(),
            description: values.description || '',
            discount_type: values.discount_type,
            discount_value: Number(values.discount_value),
            start_date: values.start_date.format('YYYY-MM-DD'),
            end_date: values.end_date.format('YYYY-MM-DD'),
            is_active: values.is_active !== false,
        };
        try {
            if (editingPromotion) {
                await updatePromotion.mutateAsync({ id: editingPromotion.id, data: payload });
            } else {
                await createPromotion.mutateAsync(payload);
            }
            setPromoModalVisible(false);
            setEditingPromotion(null);
            promoForm.resetFields();
        } catch {
            // The mutation hook displays the API message.
        }
    };

    const handleDeletePromotion = (record) => {
        Modal.confirm({
            title: 'Delete Promotion',
            content: `Are you sure you want to delete "${record.name}"?`,
            okText: 'Delete',
            okType: 'danger',
            cancelText: 'Cancel',
            onOk: async () => {
                try {
                    await deletePromotion.mutateAsync(record.id);
                } catch {
                    // The mutation hook displays the API message.
                }
            },
        });
    };

    // Upload props for menu images
    const menuUploadProps = {
        beforeUpload: (file) => {
            if (!file.type?.startsWith('image/')) {
                message.error('You can only upload image files');
                return Upload.LIST_IGNORE;
            }
            if (file.size / 1024 / 1024 >= 2) {
                message.error('Image must be smaller than 2 MB');
                return Upload.LIST_IGNORE;
            }
            return false;
        },
        onChange: ({ fileList: nextFileList }) => setMenuFileList(nextFileList.slice(-1)),
    };

    // Upload props for package images
    const packageUploadProps = {
        beforeUpload: (file) => {
            if (!file.type?.startsWith('image/')) {
                message.error('You can only upload image files');
                return Upload.LIST_IGNORE;
            }
            if (file.size / 1024 / 1024 >= 2) {
                message.error('Image must be smaller than 2 MB');
                return Upload.LIST_IGNORE;
            }
            return false;
        },
        onChange: ({ fileList: nextFileList }) => setPackageFileList(nextFileList.slice(-1)),
    };

    const filteredMenus = menus.filter((item) => (
        (!menuFilters.search || item.name?.toLowerCase().includes(menuFilters.search.toLowerCase()))
        && (!menuFilters.category_id || Number(item.category_id) === Number(menuFilters.category_id))
    ));

    const filteredPackages = packages.filter((item) => (
        !packageFilters.search || item.name?.toLowerCase().includes(packageFilters.search.toLowerCase())
    ));

    const filteredCategories = categories.filter((item) => (
        !categoryFilters.search || item.name?.toLowerCase().includes(categoryFilters.search.toLowerCase())
    ));

    const filteredPromotions = promotions.filter((item) => (
        !promotionFilters.search || item.name?.toLowerCase().includes(promotionFilters.search.toLowerCase())
    ));

    // ============================================================
    // TABLE COLUMNS
    // ============================================================
    const menuColumns = [
        { 
            title: 'ID', 
            dataIndex: 'id', 
            key: 'id', 
            width: 70, 
            render: (text) => <span className="mm-id-text">{text}</span> 
        },
        { 
            title: 'IMAGE', 
            key: 'image', 
            width: 80, 
            render: (_, record) => <Image src={record.image_url || '/images/placeholder.svg'} width={48} height={48} style={{ borderRadius: 8, objectFit: 'cover' }} preview={false} fallback="/images/placeholder.svg" /> 
        },
        { 
            title: 'NAME', 
            dataIndex: 'name', 
            key: 'name', 
            width: 200, 
            render: (text, record) => <div><div className="mm-item-name">{text}</div><div className="mm-item-category">{record.category || 'Uncategorized'}</div></div> 
        },
        { 
            title: 'DESCRIPTION', 
            dataIndex: 'description', 
            key: 'description', 
            width: 250, 
            ellipsis: true, 
            render: (text) => <Tooltip title={text}><span className="mm-item-description">{text?.substring(0, 60) || '—'}{text?.length > 60 ? '...' : ''}</span></Tooltip> 
        },
        { 
            title: 'PRICE', 
            dataIndex: 'price', 
            key: 'price', 
            width: 110, 
            align: 'right', 
            sorter: (a, b) => a.price - b.price, 
            render: (value) => <span className="mm-item-price">{currency(value)}</span> 
        },
        { 
            title: 'PREP', 
            dataIndex: 'prep_time_minutes', 
            key: 'prep_time_minutes', 
            width: 80, 
            align: 'center', 
            render: (value) => <span className="mm-item-prep">{value ? `${value} min` : '—'}</span> 
        },
        { 
            title: 'RATING', 
            key: 'rating', 
            width: 100, 
            render: (_, record) => <span><StarFilled style={{ color: '#faad14' }} /> <span className="mm-item-rating">{record.rating || 0}</span></span> 
        },
        { 
            title: 'DIETARY', 
            key: 'dietary', 
            width: 150, 
            render: (_, record) => <Space size={4} wrap>
                {record.is_vegetarian && <Tag color="green" className="mm-dietary-tag">Veg</Tag>}
                {record.is_vegan && <Tag color="lime" className="mm-dietary-tag">Vegan</Tag>}
                {record.is_gluten_free && <Tag color="gold" className="mm-dietary-tag">GF</Tag>}
                {record.is_halal && <Tag color="cyan" className="mm-dietary-tag">Halal</Tag>}
            </Space> 
        },
        { 
            title: 'FEATURED', 
            key: 'featured', 
            width: 90, 
            align: 'center', 
            render: (_, record) => <Switch checked={Boolean(record.is_popular)} loading={toggleFeatured.isPending} onChange={() => handleToggleFeatured(record)} checkedChildren={<FireOutlined />} unCheckedChildren={<FireOutlined />} style={{ backgroundColor: record.is_popular ? '#faad14' : '#d9d9d9' }} /> 
        },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 180, 
            render: (_, record) => <Space>
                <Tooltip title="View Recipe"><Button className="mm-action-btn view" icon={<EyeOutlined />} onClick={() => handleViewRecipe(record)} /></Tooltip>
                <Tooltip title="Edit"><Button className="mm-action-btn edit" icon={<EditOutlined />} onClick={() => handleEditMenu(record)} /></Tooltip>
                <Tooltip title="Delete"><Button className="mm-action-btn delete" danger icon={<DeleteOutlined />} onClick={() => handleDeleteMenu(record)} /></Tooltip>
            </Space> 
        },
    ];

    const packageColumns = [
        { 
            title: 'ID', 
            dataIndex: 'id', 
            key: 'id', 
            width: 80, 
            render: (text) => <span className="mm-id-text">{text}</span> 
        },
        { 
            title: 'IMAGE', 
            key: 'image', 
            width: 80, 
            render: (_, record) => <Image src={record.image_url || '/images/placeholder.svg'} width={48} height={48} style={{ borderRadius: 8, objectFit: 'cover' }} preview={false} fallback="/images/placeholder.svg" /> 
        },
        { 
            title: 'NAME', 
            dataIndex: 'name', 
            key: 'name', 
            width: 200, 
            render: (text) => <span className="mm-item-name">{text}</span> 
        },
        { 
            title: 'DESCRIPTION', 
            dataIndex: 'description', 
            key: 'description', 
            width: 300, 
            ellipsis: true, 
            render: (text) => <span className="mm-item-description">{text?.substring(0, 60) || '—'}{text?.length > 60 ? '...' : ''}</span> 
        },
        { 
            title: 'PRICE/PAX', 
            dataIndex: 'base_price_per_pax', 
            key: 'price', 
            width: 130, 
            render: (value) => <span className="mm-package-price">{currency(value)}</span> 
        },
        { 
            title: 'PAX', 
            key: 'pax', 
            width: 100, 
            align: 'center', 
            render: (_, record) => <Tag color="purple" className="mm-pax-tag">{record.min_pax || 0} - {record.max_pax || 0}</Tag> 
        },
        { 
            title: 'ITEMS', 
            key: 'items', 
            width: 80, 
            align: 'center', 
            render: (_, record) => <Badge count={record.items_count ?? record.menu_items?.length ?? 0} style={{ backgroundColor: '#1a7ab5' }} /> 
        },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 120, 
            render: (_, record) => <Space>
                <Tooltip title="Edit"><Button className="mm-action-btn edit" icon={<EditOutlined />} onClick={() => handleEditPackage(record)} /></Tooltip>
                <Tooltip title="Delete"><Button className="mm-action-btn delete" danger icon={<DeleteOutlined />} onClick={() => handleDeletePackage(record)} /></Tooltip>
            </Space> 
        },
    ];

    const categoryColumns = [
        { 
            title: 'ID', 
            dataIndex: 'id', 
            key: 'id', 
            width: 80, 
            render: (text) => <span className="mm-id-text">{text}</span> 
        },
        { 
            title: 'ICON', 
            dataIndex: 'icon', 
            key: 'icon', 
            width: 80, 
            render: (value) => <span style={{ fontSize: 28 }}>{renderIcon(value)}</span> 
        },
        { 
            title: 'NAME', 
            dataIndex: 'name', 
            key: 'name', 
            width: 200, 
            render: (text) => <span className="mm-item-name">{text}</span> 
        },
        { 
            title: 'SLUG', 
            dataIndex: 'slug', 
            key: 'slug', 
            width: 150, 
            render: (text) => <span className="mm-slug-text">{text}</span> 
        },
        { 
            title: 'ITEMS', 
            key: 'menu_items_count', 
            width: 80, 
            align: 'center', 
            render: (_, record) => <Badge count={record.menu_items_count || 0} style={{ backgroundColor: '#1a7ab5' }} /> 
        },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 120, 
            render: (_, record) => <Space>
                <Tooltip title="Edit"><Button className="mm-action-btn edit" icon={<EditOutlined />} onClick={() => handleEditCategory(record)} /></Tooltip>
                <Tooltip title="Delete"><Button className="mm-action-btn delete" danger icon={<DeleteOutlined />} onClick={() => handleDeleteCategory(record)} /></Tooltip>
            </Space> 
        },
    ];

    const promoColumns = [
        { 
            title: 'NAME', 
            dataIndex: 'name', 
            key: 'name', 
            width: 200, 
            render: (text) => <span className="mm-item-name">{text}</span> 
        },
        { 
            title: 'CODE', 
            dataIndex: 'code', 
            key: 'code', 
            width: 120, 
            render: (text) => <Tag color="gold" className="mm-promo-code">{text}</Tag> 
        },
        { 
            title: 'DISCOUNT', 
            key: 'discount', 
            width: 100, 
            render: (_, record) => <Tag color="red" className="mm-discount-tag">{record.discount_type === 'percentage' ? `${record.discount_value}%` : currency(record.discount_value)}</Tag> 
        },
        { 
            title: 'PERIOD', 
            key: 'period', 
            width: 220, 
            render: (_, record) => <span className="mm-period-text">{dayjs(record.start_date).format('MMM DD, YYYY')} → {dayjs(record.end_date).format('MMM DD, YYYY')}</span> 
        },
        { 
            title: 'ACTIONS', 
            key: 'actions', 
            width: 120, 
            render: (_, record) => <Space>
                <Tooltip title="Edit"><Button className="mm-action-btn edit" icon={<EditOutlined />} onClick={() => handleEditPromotion(record)} /></Tooltip>
                <Tooltip title="Delete"><Button className="mm-action-btn delete" danger icon={<DeleteOutlined />} onClick={() => handleDeletePromotion(record)} /></Tooltip>
            </Space> 
        },
    ];

    // ============================================================
    // MODAL HEADER COMPONENT
    // ============================================================
    const ModalHeader = ({ icon, title, subtitle }) => (
        <div className="mm-modal-header-clean">
            <div className="mm-modal-title-icon">{icon}</div>
            <div className="mm-modal-title-text">{title}</div>
            <div className="mm-modal-badge">{subtitle}</div>
        </div>
    );

    // ============================================================
    // THEME
    // ============================================================
    const lightTheme = {
        token: {
            colorPrimary: '#1a7ab5',
            colorBgContainer: '#ffffff',
            colorBgElevated: '#ffffff',
            colorBorderSecondary: '#e4e9f0',
            borderRadius: 8,
            fontFamily: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
            colorText: '#111827',
            colorTextSecondary: '#4b5563',
        },
        components: {
            Table: { headerBg: '#f8fafc', headerColor: '#111827', rowHoverBg: '#fafcff' },
            Card: { colorBgContainer: '#ffffff' },
        },
    };

    const darkTheme = {
        token: {
            colorPrimary: '#3b82f6',
            colorBgContainer: '#1e293b',
            colorBgElevated: '#0f172a',
            colorBorderSecondary: '#334155',
            borderRadius: 8,
            fontFamily: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
            colorText: '#f1f5f9',
            colorTextSecondary: '#94a3b8',
        },
        components: {
            Table: { headerBg: '#0f172a', headerColor: '#f1f5f9', rowHoverBg: '#334155' },
            Card: { colorBgContainer: '#1e293b' },
        },
    };

    // Check if data exists in cache
    const hasData = menuQuery.data || categoryQuery.data || packageQuery.data || promotionQuery.data;
    const isInitialLoading = !hasData && 
        (menuQuery.isLoading || categoryQuery.isLoading || packageQuery.isLoading || promotionQuery.isLoading);

    if (isInitialLoading) {
        return <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}><Spin size="large" tip="Loading menu data..." /></div>;
    }

    const handleTabChange = (key) => {
        setActiveMainTab(key);
    };

    // ============================================================
    // RENDER
    // ============================================================
    return (
        <ConfigProvider theme={isDarkMode ? darkTheme : lightTheme}>
            <div className={`mm-menu-container ${isDarkMode ? 'mm-dark-mode' : ''}`}>
                {/* ==================== HEADER ==================== */}
                <div className="mm-header">
                    <div className="mm-header-left">
                        <div className="mm-logo-icon"><MenuOutlined /></div>
                        <div className="mm-header-info">
                            <h1>Menu Management</h1>
                            <div className="mm-breadcrumb">Dashboard / Menu / Management</div>
                        </div>
                    </div>
                    <div className="mm-header-right">
                        <div className="mm-date-display"><CalendarOutlined /><span>{new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span></div>
                        <Tooltip title={isDarkMode ? 'Light Mode' : 'Dark Mode'}>
                            <Button icon={isDarkMode ? <SunOutlined /> : <MoonOutlined />} onClick={() => setIsDarkMode((value) => !value)} />
                        </Tooltip>
                        <Button icon={<ReloadOutlined />} onClick={handleRefresh}>Refresh</Button>
                        <Button icon={<PrinterOutlined />} onClick={handlePrint}>Print</Button>
                        <Button icon={<ExportOutlined />} onClick={handleExport}>Export</Button>
                    </div>
                </div>

                {/* ==================== STATS CARDS ==================== */}
                <div className="mm-dashboard-grid">
                    <div className="mm-stat-card">
                        <div className="mm-stat-icon blue"><MenuOutlined /></div>
                        <div className="mm-stat-content">
                            <div className="mm-stat-value">{menus.length}</div>
                            <div className="mm-stat-label">Total Items</div>
                        </div>
                    </div>
                    <div className="mm-stat-card">
                        <div className="mm-stat-icon green"><CheckCircleOutlined /></div>
                        <div className="mm-stat-content">
                            <div className="mm-stat-value">{menus.filter((item) => item.is_available !== false).length}</div>
                            <div className="mm-stat-label">Active Items</div>
                        </div>
                    </div>
                    <div className="mm-stat-card">
                        <div className="mm-stat-icon orange"><FireOutlined /></div>
                        <div className="mm-stat-content">
                            <div className="mm-stat-value">{menus.filter((item) => item.is_popular).length}</div>
                            <div className="mm-stat-label">Featured</div>
                        </div>
                    </div>
                    <div className="mm-stat-card">
                        <div className="mm-stat-icon purple"><AppstoreOutlined /></div>
                        <div className="mm-stat-content">
                            <div className="mm-stat-value">{packages.length}</div>
                            <div className="mm-stat-label">Packages</div>
                        </div>
                    </div>
                    <div className="mm-stat-card">
                        <div className="mm-stat-icon pink"><GiftOutlined /></div>
                        <div className="mm-stat-content">
                            <div className="mm-stat-value">{promotions.length}</div>
                            <div className="mm-stat-label">Promotions</div>
                        </div>
                    </div>
                </div>

                {/* ==================== MAIN CARD ==================== */}
                <Card className="mm-main-card" bordered={false}>
                    <Tabs activeKey={activeMainTab} onChange={handleTabChange} className="mm-tabs" destroyInactiveTabPane={true}>
                        {/* ==================== MENU ITEMS TAB ==================== */}
                        <TabPane tab={<span><MenuOutlined /> Menu Items ({menus.length})</span>} key="menus">
                            <div className="mm-table-container">
                                <div className="mm-filter-bar">
                                    <div className="mm-filter-left">
                                        <Select 
                                            value={menuFilters.category_id || 'all'} 
                                            onChange={(value) => setMenuFilters((filters) => ({ ...filters, category_id: value === 'all' ? null : value }))} 
                                            className="mm-filter-select" 
                                            style={{ width: 200 }}
                                        >
                                            <Option value="all">All Categories</Option>
                                            {categories.map((category) => <Option key={category.id} value={category.id}>{category.name}</Option>)}
                                        </Select>
                                    </div>
                                    <div className="mm-filter-right">
                                        <Search 
                                            placeholder="Search menu items..." 
                                            allowClear 
                                            onChange={(event) => setMenuFilters((filters) => ({ ...filters, search: event.target.value }))} 
                                            style={{ width: 280 }} 
                                            className="mm-search-input-custom" 
                                        />
                                        <Button type="primary" icon={<PlusOutlined />} onClick={handleAddMenu}>Add Item</Button>
                                    </div>
                                </div>
                                <div className="mm-table-wrapper">
                                    <Table 
                                        columns={menuColumns} 
                                        dataSource={filteredMenus} 
                                        rowKey="id" 
                                        className="mm-professional-table"
                                        footer={
                                            filteredMenus.length === 0
                                                ? () => renderEmptyPaginationFooter('menu items')
                                                : undefined
                                        }
                                        pagination={{
                                            pageSize: 7,
                                            showSizeChanger: true,
                                            showTotal: (total) => `Total ${total} items`,
                                            itemRender: renderPaginationItem,
                                            pageSizeOptions: ['5', '10', '20', '50'],
                                        }}
                                        scroll={{ x: 1400 }} 
                                    />
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== PACKAGES TAB ==================== */}
                        <TabPane tab={<span><AppstoreOutlined /> Packages ({packages.length})</span>} key="packages">
                            <div className="mm-table-container">
                                <div className="mm-filter-bar">
                                    <div />
                                    <div className="mm-filter-right">
                                        <Search 
                                            placeholder="Search packages..." 
                                            allowClear 
                                            onChange={(event) => setPackageFilters((filters) => ({ ...filters, search: event.target.value }))} 
                                            style={{ width: 280 }} 
                                            className="mm-search-input-custom" 
                                        />
                                        <Button type="primary" icon={<PlusOutlined />} onClick={handleAddPackage}>Create Package</Button>
                                    </div>
                                </div>
                                <div className="mm-table-wrapper">
                                    <Table 
                                        columns={packageColumns} 
                                        dataSource={filteredPackages} 
                                        rowKey="id" 
                                        className="mm-professional-table"
                                        footer={
                                            filteredPackages.length === 0
                                                ? () => renderEmptyPaginationFooter('packages')
                                                : undefined
                                        }
                                        pagination={{
                                            pageSize: 5,
                                            showSizeChanger: true,
                                            showTotal: (total) => `Total ${total} packages`,
                                            itemRender: renderPaginationItem,
                                            pageSizeOptions: ['5', '10', '20', '50'],
                                        }}
                                    />
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== CATEGORIES TAB ==================== */}
                        <TabPane tab={<span><TagOutlined /> Categories ({categories.length})</span>} key="categories">
                            <div className="mm-table-container">
                                <div className="mm-filter-bar">
                                    <div />
                                    <div className="mm-filter-right">
                                        <Search 
                                            placeholder="Search categories..." 
                                            allowClear 
                                            onChange={(event) => setCategoryFilters((filters) => ({ ...filters, search: event.target.value }))} 
                                            style={{ width: 280 }} 
                                            className="mm-search-input-custom" 
                                        />
                                        <Button type="primary" icon={<PlusOutlined />} onClick={handleAddCategory}>Add Category</Button>
                                    </div>
                                </div>
                                <div className="mm-table-wrapper">
                                    <Table 
                                        columns={categoryColumns} 
                                        dataSource={filteredCategories} 
                                        rowKey="id" 
                                        className="mm-professional-table"
                                        footer={
                                            filteredCategories.length === 0
                                                ? () => renderEmptyPaginationFooter('categories')
                                                : undefined
                                        }
                                        pagination={{
                                            pageSize: 5,
                                            showSizeChanger: true,
                                            showTotal: (total) => `Total ${total} categories`,
                                            itemRender: renderPaginationItem,
                                            pageSizeOptions: ['5', '10', '20', '50'],
                                        }}
                                    />
                                </div>
                            </div>
                        </TabPane>

                        {/* ==================== PROMOTIONS TAB ==================== */}
                        <TabPane tab={<span><GiftOutlined /> Promotions ({promotions.length})</span>} key="promotions">
                            <div className="mm-table-container">
                                <div className="mm-filter-bar">
                                    <div />
                                    <div className="mm-filter-right">
                                        <Search 
                                            placeholder="Search promotions..." 
                                            allowClear 
                                            onChange={(event) => setPromotionFilters((filters) => ({ ...filters, search: event.target.value }))} 
                                            style={{ width: 280 }} 
                                            className="mm-search-input-custom" 
                                        />
                                        <Button type="primary" icon={<PlusOutlined />} onClick={handleAddPromotion}>Create Promotion</Button>
                                    </div>
                                </div>
                                <div className="mm-table-wrapper">
                                    <Table 
                                        columns={promoColumns} 
                                        dataSource={filteredPromotions} 
                                        rowKey="id" 
                                        className="mm-professional-table"
                                        footer={
                                            filteredPromotions.length === 0
                                                ? () => renderEmptyPaginationFooter('promotions')
                                                : undefined
                                        }
                                        pagination={{
                                            pageSize: 5,
                                            showSizeChanger: true,
                                            showTotal: (total) => `Total ${total} promotions`,
                                            itemRender: renderPaginationItem,
                                            pageSizeOptions: ['5', '10', '20', '50'],
                                        }}
                                    />
                                </div>
                            </div>
                        </TabPane>
                    </Tabs>
                </Card>

                {/* ============================================================
                    MENU ITEM MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="mm-modal-header-clean">
                            <div className="mm-modal-title-icon">{selectedItem ? <EditOutlined /> : <PlusOutlined />}</div>
                            <div className="mm-modal-title-text">{selectedItem ? 'Edit Menu Item' : 'Create New Menu Item'}</div>
                            <div className="mm-modal-badge">{selectedItem ? `ID: ${selectedItem.id}` : 'New'}</div>
                        </div>
                    }
                    open={menuModalVisible}
                    onCancel={closeMenuModal}
                    width={800}
                    className="mm-modal-clean"
                    footer={null}
                    maskClosable={false}
                    keyboard={false}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="mm-modal-clean-content">
                        <Steps 
                            current={modalCurrentStep} 
                            onChange={setModalCurrentStep} 
                            className="mm-modal-steps"
                            items={[
                                { title: 'Basic Info', icon: <InfoCircleOutlined /> },
                                { title: 'Dietary', icon: <CheckCircleOutlined /> },
                                { title: 'Recipe', icon: <BoxPlotOutlined /> },
                                { title: 'Media', icon: <UploadOutlined /> },
                            ]} 
                        />
                        <Form form={menuForm} layout="vertical" preserve>
                            {/* Step 1: Basic Info */}
                            <div style={{ display: modalCurrentStep === 0 ? 'block' : 'none' }}>
                                <Row gutter={16}>
                                    <Col span={12}>
                                        <Form.Item name="name" label="Menu Name" rules={[{ required: true, message: 'Please enter menu name' }]}>
                                            <Input placeholder="e.g., Chicken Adobo" size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={12}>
                                        <Form.Item name="category_id" label="Category" rules={[{ required: true, message: 'Please select a category' }]}>
                                            <Select placeholder="Select category" size="large" className="mm-select-enhanced" showSearch optionFilterProp="children">
                                                {categories.map((category) => <Option key={category.id} value={category.id}>{category.name}</Option>)}
                                            </Select>
                                        </Form.Item>
                                    </Col>
                                </Row>
                                <Form.Item name="description" label="Description">
                                    <TextArea rows={3} placeholder="Describe the menu item in detail" className="mm-textarea-enhanced" />
                                </Form.Item>
                                <Row gutter={16}>
                                    <Col span={8}>
                                        <Form.Item name="price" label="Price (₱)" rules={[{ required: true, message: 'Please enter price' }]}>
                                            <InputNumber min={0} step={0.01} style={{ width: '100%' }} prefix="₱" placeholder="0.00" size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={8}>
                                        <Form.Item name="prep_time_minutes" label="Prep Time (mins)">
                                            <InputNumber min={0} style={{ width: '100%' }} placeholder="15" size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={8}>
                                        <Form.Item name="serving_size" label="Serving Size">
                                            <InputNumber min={1} style={{ width: '100%' }} placeholder="1" size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                </Row>
                            </div>

                            {/* Step 2: Dietary */}
                            <div style={{ display: modalCurrentStep === 1 ? 'block' : 'none' }}>
                                <Alert message="Dietary Information" description="Select applicable dietary options" type="info" showIcon className="mm-modal-alert" />
                                <div className="mm-dietary-section">
                                    <Row gutter={16}>
                                        <Col span={8}>
                                            <Form.Item name="is_vegetarian" label="Vegetarian" valuePropName="checked">
                                                <Switch className="mm-switch-enhanced" />
                                            </Form.Item>
                                        </Col>
                                        <Col span={8}>
                                            <Form.Item name="is_vegan" label="Vegan" valuePropName="checked">
                                                <Switch className="mm-switch-enhanced" />
                                            </Form.Item>
                                        </Col>
                                        <Col span={8}>
                                            <Form.Item name="is_gluten_free" label="Gluten Free" valuePropName="checked">
                                                <Switch className="mm-switch-enhanced" />
                                            </Form.Item>
                                        </Col>
                                        <Col span={8}>
                                            <Form.Item name="is_halal" label="Halal" valuePropName="checked">
                                                <Switch className="mm-switch-enhanced" />
                                            </Form.Item>
                                        </Col>
                                        <Col span={8}>
                                            <Form.Item name="is_available" label="Available" valuePropName="checked">
                                                <Switch className="mm-switch-enhanced" />
                                            </Form.Item>
                                        </Col>
                                        <Col span={8}>
                                            <Form.Item name="is_popular" label="Featured" valuePropName="checked">
                                                <Switch className="mm-switch-enhanced" />
                                            </Form.Item>
                                        </Col>
                                    </Row>
                                </div>
                                <Form.Item name="allergens" label="Allergens">
                                    <Select mode="tags" placeholder="e.g., Nuts, Dairy" size="large" className="mm-select-enhanced" />
                                </Form.Item>
                                <Form.Item name="nutritional_info" label="Nutritional Info">
                                    <TextArea rows={2} placeholder="Calories, Protein, Carbs, etc." className="mm-textarea-enhanced" />
                                </Form.Item>
                            </div>

                            {/* Step 3: Recipe */}
                            <div style={{ display: modalCurrentStep === 2 ? 'block' : 'none' }}>
                                <div className="mm-recipe-header">
                                    <Button onClick={handleAddRecipeIngredient} icon={<PlusOutlined />} className="mm-btn-outline">Add Ingredient</Button>
                                    <div className="mm-recipe-cost">
                                        <Text strong>Total Cost: </Text>
                                        <Text className="mm-recipe-total">{currency(calculateTotalRecipeCost())}</Text>
                                    </div>
                                </div>
                                {recipeIngredients.length === 0 ? (
                                    <Empty description="No ingredients added" className="mm-empty-state" />
                                ) : (
                                    <Table 
                                        dataSource={recipeIngredients} 
                                        rowKey="id" 
                                        size="small" 
                                        pagination={false} 
                                        className="mm-recipe-table"
                                        columns={[
                                            { 
                                                title: 'Ingredient', 
                                                width: 220, 
                                                render: (_, row) => (
                                                    <Select 
                                                        showSearch 
                                                        optionFilterProp="children" 
                                                        placeholder="Select ingredient" 
                                                        value={row.ingredient_id || undefined} 
                                                        style={{ width: '100%' }} 
                                                        className="mm-select-enhanced"
                                                        onChange={(value) => handleUpdateRecipeIngredient(row.id, 'ingredient_id', value)}
                                                    >
                                                        {ingredients.map((ingredient) => <Option key={ingredient.id} value={ingredient.id}>{ingredient.name}</Option>)}
                                                    </Select>
                                                ) 
                                            },
                                            { 
                                                title: 'Qty/Pax', 
                                                width: 95, 
                                                render: (_, row) => (
                                                    <InputNumber 
                                                        min={0.001} 
                                                        step={0.001} 
                                                        value={row.quantity_per_pax} 
                                                        onChange={(value) => handleUpdateRecipeIngredient(row.id, 'quantity_per_pax', value)} 
                                                        style={{ width: '100%' }} 
                                                        className="mm-input-enhanced" 
                                                    />
                                                ) 
                                            },
                                            { 
                                                title: 'Unit', 
                                                width: 85, 
                                                render: (_, row) => (
                                                    <Input 
                                                        value={row.unit} 
                                                        onChange={(event) => handleUpdateRecipeIngredient(row.id, 'unit', event.target.value)} 
                                                        className="mm-input-enhanced" 
                                                    />
                                                ) 
                                            },
                                            { 
                                                title: 'Cost', 
                                                width: 110, 
                                                render: (_, row) => currency(Number(row.quantity_per_pax || 0) * Number(ingredientById.get(Number(row.ingredient_id))?.unit_cost || 0)) 
                                            },
                                            { 
                                                title: '', 
                                                width: 50, 
                                                render: (_, row) => (
                                                    <Button danger icon={<DeleteOutlined />} onClick={() => handleRemoveRecipeIngredient(row.id)} size="small" className="mm-btn-icon" />
                                                ) 
                                            },
                                        ]} 
                                    />
                                )}
                                {calculateTotalRecipeCost() > 0 && Number(menuForm.getFieldValue('price') || 0) > 0 && (
                                    <div className="mm-recipe-summary">
                                        <Row gutter={16}>
                                            <Col span={12}>
                                                <Progress 
                                                    percent={Math.max(0, Math.min(100, Math.round(calculateProfitMargin())))} 
                                                    status={calculateProfitMargin() > 50 ? 'success' : 'normal'} 
                                                    className="mm-progress-bar"
                                                />
                                            </Col>
                                            <Col span={12}>
                                                <div className="mm-profit-display">
                                                    <Text strong>Profit: </Text>
                                                    <Text className="mm-profit-amount">{currency(Number(menuForm.getFieldValue('price') || 0) - calculateTotalRecipeCost())}</Text>
                                                </div>
                                            </Col>
                                        </Row>
                                    </div>
                                )}
                            </div>

                            {/* Step 4: Media */}
                            <div style={{ display: modalCurrentStep === 3 ? 'block' : 'none' }}>
                                <Alert message="Menu Image" description="Upload a high-quality image (max 2 MB)" type="info" showIcon className="mm-modal-alert" />
                                <div className="mm-upload-section">
                                    <Upload 
                                        {...menuUploadProps} 
                                        accept="image/*" 
                                        listType="picture-card" 
                                        fileList={menuFileList} 
                                        maxCount={1}
                                        className="mm-upload-picture"
                                    >
                                        {menuFileList.length < 1 && (
                                            <div>
                                                <PlusOutlined />
                                                <div style={{ marginTop: 8 }}>Upload</div>
                                            </div>
                                        )}
                                    </Upload>
                                </div>
                                <Form.Item name="ingredients_list" label="Ingredients List">
                                    <TextArea rows={2} placeholder="List main ingredients separated by commas" className="mm-textarea-enhanced" />
                                </Form.Item>
                            </div>
                        </Form>
                        <Divider className="mm-modal-divider" />
                        <div className="mm-modal-footer-enhanced">
                            <Button onClick={() => setModalCurrentStep((step) => Math.max(0, step - 1))} disabled={modalCurrentStep === 0} className="mm-btn-secondary">
                                Previous
                            </Button>
                            <Space>
                                <Button onClick={closeMenuModal} className="mm-btn-cancel">Cancel</Button>
                                {modalCurrentStep < 3 ? (
                                    <Button type="primary" onClick={async () => { 
                                        try { 
                                            if (modalCurrentStep === 0) await menuForm.validateFields(['name', 'category_id', 'price']); 
                                            setModalCurrentStep((step) => step + 1); 
                                        } catch { /* Form shows errors. */ } 
                                    }} className="mm-btn-primary">
                                        Next
                                    </Button>
                                ) : (
                                    <Button type="primary" loading={createMenuItem.isPending || updateMenuItem.isPending} onClick={handleSaveMenu} className="mm-btn-primary">
                                        <SaveOutlined /> {selectedItem ? 'Update' : 'Create'}
                                    </Button>
                                )}
                            </Space>
                        </div>
                    </div>
                </Modal>

                {/* ============================================================
                    PACKAGE MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="mm-modal-header-clean">
                            <div className="mm-modal-title-icon"><AppstoreOutlined /></div>
                            <div className="mm-modal-title-text">{selectedPackageItem ? 'Edit Package' : 'Create New Package'}</div>
                            <div className="mm-modal-badge">{selectedPackageItem ? `ID: ${selectedPackageItem.id}` : 'New'}</div>
                        </div>
                    }
                    open={packageModalVisible}
                    onCancel={closePackageModal}
                    width={950}
                    className="mm-modal-clean"
                    footer={null}
                    maskClosable={false}
                    keyboard={false}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="mm-modal-clean-content">
                        <Steps 
                            current={packageCurrentStep} 
                            onChange={setPackageCurrentStep} 
                            className="mm-modal-steps"
                            items={[
                                { title: 'Package Info', icon: <InfoCircleOutlined /> },
                                { title: 'Select Items', icon: <MenuOutlined /> },
                                { title: 'Review', icon: <CheckCircleOutlined /> },
                            ]} 
                        />
                        <Form form={packageForm} layout="vertical" preserve>
                            {/* Step 0: Package Info */}
                            <div style={{ display: packageCurrentStep === 0 ? 'block' : 'none' }}>
                                <Row gutter={20}>
                                    <Col span={12}>
                                        <Form.Item name="name" label="Package Name" rules={[{ required: true, message: 'Please enter package name' }]}>
                                            <Input placeholder="e.g., Family Feast Bundle" size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={12}>
                                        <Form.Item name="base_price_per_pax" label="Price per Person (₱)" rules={[{ required: true, message: 'Please enter price' }]}>
                                            <InputNumber min={0} step={0.01} style={{ width: '100%' }} prefix="₱" placeholder="0.00" size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                </Row>
                                <Form.Item name="description" label="Package Description">
                                    <TextArea rows={3} placeholder="Describe what is included in this package" className="mm-textarea-enhanced" />
                                </Form.Item>
                                <Row gutter={20}>
                                    <Col span={12}>
                                        <Form.Item name="min_pax" label="Minimum Guests" rules={[{ required: true, message: 'Minimum guests required' }]}>
                                            <InputNumber min={1} style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={12}>
                                        <Form.Item name="max_pax" label="Maximum Guests" rules={[{ required: true, message: 'Maximum guests required' }]}>
                                            <InputNumber min={1} style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                </Row>
                                <Row gutter={20}>
                                    <Col span={12}>
                                        <Form.Item name="price_per_additional_pax" label="Additional Person Price">
                                            <InputNumber min={0} step={0.01} style={{ width: '100%' }} prefix="₱" size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={12}>
                                        <Form.Item name="default_duration_hours" label="Duration (hours)">
                                            <InputNumber min={1} style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                </Row>
                                <Row gutter={20}>
                                    <Col span={8}>
                                        <Form.Item name="is_active" label="Active Package" valuePropName="checked">
                                            <Switch className="mm-switch-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={8}>
                                        <Form.Item name="is_featured" label="Featured Package" valuePropName="checked">
                                            <Switch className="mm-switch-enhanced" />
                                        </Form.Item>
                                    </Col>
                                    <Col span={8}>
                                        <Form.Item name="sort_order" label="Sort Order">
                                            <InputNumber min={0} style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                                        </Form.Item>
                                    </Col>
                                </Row>
                                <Form.Item name="inclusions" label="Inclusions">
                                    <Select mode="tags" placeholder="Press Enter after each inclusion" size="large" className="mm-select-enhanced" />
                                </Form.Item>
                                <Form.Item name="exclusions" label="Exclusions">
                                    <Select mode="tags" placeholder="Press Enter after each exclusion" size="large" className="mm-select-enhanced" />
                                </Form.Item>

                                <Divider orientation="left" className="mm-divider-enhanced">Package Image</Divider>
                                <Alert message="Upload Package Image" description="Upload a high-quality image for this package (max 2 MB)" type="info" showIcon className="mm-modal-alert" />
                                <div className="mm-upload-section">
                                    <Upload 
                                        {...packageUploadProps} 
                                        accept="image/*" 
                                        listType="picture-card" 
                                        fileList={packageFileList} 
                                        maxCount={1}
                                        className="mm-upload-picture"
                                    >
                                        {packageFileList.length < 1 && (
                                            <div>
                                                <PlusOutlined />
                                                <div style={{ marginTop: 8 }}>Upload</div>
                                            </div>
                                        )}
                                    </Upload>
                                </div>
                            </div>

                            {/* Step 1: Select Items */}
                            <div style={{ display: packageCurrentStep === 1 ? 'block' : 'none' }}>
                                <Alert message="Select Menu Items" description="Choose items to include in this package. You can adjust quantities per person." type="info" showIcon className="mm-modal-alert" />
                                <div className="mm-package-items-container">
                                    <div className="mm-package-available-panel">
                                        <div className="mm-package-panel-header">
                                            <span className="mm-package-panel-title"><MenuOutlined /> Available Items</span>
                                            <span className="mm-package-panel-count">{menus.length}</span>
                                        </div>
                                        <div className="mm-package-panel-list">
                                            <Table 
                                                dataSource={menus} 
                                                rowKey="id" 
                                                size="small" 
                                                pagination={false} 
                                                className="mm-package-item-table"
                                                columns={[
                                                    { title: 'Name', dataIndex: 'name', render: (text) => <span className="mm-item-name">{text}</span> },
                                                    { title: 'Price', dataIndex: 'price', width: 95, render: (value) => <span className="mm-item-price">{currency(value)}</span> },
                                                    { 
                                                        title: '', 
                                                        width: 50, 
                                                        render: (_, row) => (
                                                            <Button size="small" icon={<PlusOutlined />} onClick={() => handleAddMenuItemToPackage(row)} className="mm-package-add-btn" />
                                                        ) 
                                                    }
                                                ]} 
                                            />
                                        </div>
                                    </div>
                                    <div className="mm-package-selected-panel">
                                        <div className="mm-package-panel-header">
                                            <span className="mm-package-panel-title"><CheckCircleOutlined /> Selected Items</span>
                                            <span className="mm-package-panel-count">{selectedMenuItems.length}</span>
                                        </div>
                                        <div className="mm-package-panel-list">
                                            {selectedMenuItems.length === 0 ? (
                                                <Empty description="No items selected" image={Empty.PRESENTED_IMAGE_SIMPLE} className="mm-package-empty-selected" />
                                            ) : (
                                                <Table 
                                                    dataSource={selectedMenuItems} 
                                                    rowKey="menu_item_id" 
                                                    size="small" 
                                                    pagination={false} 
                                                    className="mm-package-item-table mm-package-selected-table"
                                                    columns={[
                                                        { title: 'Name', dataIndex: 'name', render: (text) => <span className="mm-item-name">{text}</span> },
                                                        { 
                                                            title: 'Qty', 
                                                            width: 80, 
                                                            render: (_, row) => (
                                                                <InputNumber 
                                                                    min={1} 
                                                                    value={row.quantity} 
                                                                    onChange={(value) => handleUpdateMenuItemQuantity(row.menu_item_id, value)} 
                                                                    size="small" 
                                                                    style={{ width: 60 }} 
                                                                    className="mm-input-enhanced" 
                                                                />
                                                            ) 
                                                        },
                                                        { title: 'Price', width: 95, dataIndex: 'price', render: (value) => <span className="mm-item-price">{currency(value)}</span> },
                                                        { 
                                                            title: '', 
                                                            width: 50, 
                                                            render: (_, row) => (
                                                                <Button danger size="small" icon={<DeleteOutlined />} onClick={() => handleRemoveMenuItemFromPackage(row.menu_item_id)} className="mm-package-remove-btn" />
                                                            ) 
                                                        }
                                                    ]} 
                                                />
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Step 2: Review */}
                            <div style={{ display: packageCurrentStep === 2 ? 'block' : 'none' }}>
                                <Alert message="Review Package Details" description="Please review all information before saving the package" type="success" showIcon className="mm-modal-alert" />
                                <div className="mm-package-review-summary">
                                    <div className="mm-package-review-header">
                                        <div>
                                            <div className="mm-package-review-name">{packageForm.getFieldValue('name') || 'Package Name'}</div>
                                            <div className="mm-package-review-desc">{packageForm.getFieldValue('description') || 'No description provided'}</div>
                                        </div>
                                        <Tag color="blue" className="mm-package-review-price">{currency(packageForm.getFieldValue('base_price_per_pax'))} / pax</Tag>
                                    </div>
                                    {packageFileList.length > 0 && packageFileList[0].url && (
                                        <div className="mm-package-review-image">
                                            <Image src={packageFileList[0].url} width={120} height={80} style={{ borderRadius: 8, objectFit: 'cover' }} preview={false} />
                                        </div>
                                    )}
                                    <Divider className="mm-divider-enhanced" />
                                    <Row gutter={16}>
                                        <Col span={12}>
                                            <span className="mm-package-review-icon"><TeamOutlined /></span>
                                            <span className="mm-package-review-label">Guest Range:</span>
                                            <strong>{packageForm.getFieldValue('min_pax') || 0} - {packageForm.getFieldValue('max_pax') || 0}</strong>
                                        </Col>
                                        <Col span={12}>
                                            <span className="mm-package-review-icon"><MenuOutlined /></span>
                                            <span className="mm-package-review-label">Menu Items:</span>
                                            <strong>{selectedMenuItems.length}</strong>
                                        </Col>
                                    </Row>
                                </div>
                                <Table 
                                    dataSource={selectedMenuItems} 
                                    rowKey="menu_item_id" 
                                    size="small" 
                                    pagination={false} 
                                    className="mm-package-review-table"
                                    columns={[
                                        { title: 'Item', dataIndex: 'name', render: (text) => <span className="mm-item-name">{text}</span> },
                                        { title: 'Qty/Pax', width: 100, render: (_, row) => `${row.quantity || 1}x` },
                                        { title: 'Price', width: 110, dataIndex: 'price', render: currency },
                                        { title: 'Total/Pax', width: 120, render: (_, row) => currency(Number(row.price || 0) * Number(row.quantity || 1)) }
                                    ]} 
                                />
                            </div>
                        </Form>
                        <Divider className="mm-modal-divider" />
                        <div className="mm-modal-footer-enhanced">
                            <Button onClick={() => setPackageCurrentStep((step) => Math.max(0, step - 1))} disabled={packageCurrentStep === 0} className="mm-btn-secondary">
                                Previous
                            </Button>
                            <Space>
                                <Button onClick={closePackageModal} className="mm-btn-cancel">Cancel</Button>
                                {packageCurrentStep < 2 ? (
                                    <Button type="primary" onClick={async () => { 
                                        try { 
                                            if (packageCurrentStep === 0) {
                                                await packageForm.validateFields(['name', 'base_price_per_pax', 'min_pax', 'max_pax']);
                                            }
                                            setPackageCurrentStep((step) => step + 1); 
                                        } catch { /* Form shows errors. */ } 
                                    }} className="mm-btn-primary">
                                        Next
                                    </Button>
                                ) : (
                                    <Button type="primary" loading={createPackage.isPending || updatePackage.isPending} onClick={handleSavePackage} className="mm-btn-primary">
                                        <SaveOutlined /> {selectedPackageItem ? 'Update Package' : 'Create Package'}
                                    </Button>
                                )}
                            </Space>
                        </div>
                    </div>
                </Modal>

                {/* ============================================================
                    CATEGORY MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="mm-modal-header-clean">
                            <div className="mm-modal-title-icon"><TagOutlined /></div>
                            <div className="mm-modal-title-text">{editingCategory ? 'Edit Category' : 'Create Category'}</div>
                            <div className="mm-modal-badge">{editingCategory ? `ID: ${editingCategory.id}` : 'New'}</div>
                        </div>
                    }
                    open={categoryModalVisible}
                    onCancel={() => { setCategoryModalVisible(false); setEditingCategory(null); categoryForm.resetFields(); }}
                    width={550}
                    className="mm-modal-clean"
                    footer={null}
                    maskClosable={false}
                    keyboard={false}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="mm-modal-clean-content">
                        <Form form={categoryForm} layout="vertical" onFinish={handleSaveCategory}>
                            <Form.Item name="name" label="Category Name" rules={[{ required: true, message: 'Please enter category name' }]}>
                                <Input placeholder="e.g., Appetizers" size="large" className="mm-input-enhanced" />
                            </Form.Item>
                            <Form.Item name="description" label="Description">
                                <TextArea rows={2} placeholder="Brief description" className="mm-textarea-enhanced" />
                            </Form.Item>
                            <Form.Item name="icon" label="Icon">
                                <ProfessionalIconPicker />
                            </Form.Item>
                            <Form.Item name="display_order" label="Display Order">
                                <InputNumber min={0} style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                            </Form.Item>
                            <Form.Item name="is_active" label="Active Category" valuePropName="checked">
                                <Switch className="mm-switch-enhanced" />
                            </Form.Item>
                            <div className="mm-modal-footer-enhanced">
                                <Space>
                                    <Button onClick={() => { setCategoryModalVisible(false); setEditingCategory(null); categoryForm.resetFields(); }} className="mm-btn-cancel">Cancel</Button>
                                    <Button type="primary" htmlType="submit" loading={createCategory.isPending || updateCategory.isPending} className="mm-btn-primary">
                                        <SaveOutlined /> Save
                                    </Button>
                                </Space>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ============================================================
                    PROMOTION MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="mm-modal-header-clean">
                            <div className="mm-modal-title-icon"><GiftOutlined /></div>
                            <div className="mm-modal-title-text">{editingPromotion ? 'Edit Promotion' : 'Create Promotion'}</div>
                            <div className="mm-modal-badge">{editingPromotion ? `ID: ${editingPromotion.id}` : 'New'}</div>
                        </div>
                    }
                    open={promoModalVisible}
                    onCancel={() => { setPromoModalVisible(false); setEditingPromotion(null); promoForm.resetFields(); }}
                    width={600}
                    className="mm-modal-clean"
                    footer={null}
                    maskClosable={false}
                    keyboard={false}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    <div className="mm-modal-clean-content">
                        <Form form={promoForm} layout="vertical" onFinish={handleSavePromotion}>
                            <Form.Item name="name" label="Promotion Name" rules={[{ required: true, message: 'Please enter promotion name' }]}>
                                <Input placeholder="e.g., Holiday Special" size="large" className="mm-input-enhanced" />
                            </Form.Item>
                            <Form.Item name="code" label="Promo Code" rules={[{ required: true, message: 'Please enter promo code' }]}>
                                <Input placeholder="e.g., HOLIDAY10" size="large" className="mm-input-enhanced" style={{ textTransform: 'uppercase' }} />
                            </Form.Item>
                            <Form.Item name="description" label="Description">
                                <TextArea rows={2} placeholder="Promotion details" className="mm-textarea-enhanced" />
                            </Form.Item>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="discount_type" label="Type" rules={[{ required: true }]}>
                                        <Select size="large" className="mm-select-enhanced">
                                            <Option value="percentage">Percentage (%)</Option>
                                            <Option value="fixed">Fixed (₱)</Option>
                                        </Select>
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="discount_value" label="Value" rules={[{ required: true }]}>
                                        <InputNumber min={0} step={0.01} style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Row gutter={16}>
                                <Col span={12}>
                                    <Form.Item name="start_date" label="Start Date" rules={[{ required: true }]}>
                                        <DatePicker style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="end_date" label="End Date" rules={[{ required: true }]}>
                                        <DatePicker style={{ width: '100%' }} size="large" className="mm-input-enhanced" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Form.Item name="is_active" label="Active Promotion" valuePropName="checked">
                                <Switch className="mm-switch-enhanced" />
                            </Form.Item>
                            <div className="mm-modal-footer-enhanced">
                                <Space>
                                    <Button onClick={() => { setPromoModalVisible(false); setEditingPromotion(null); promoForm.resetFields(); }} className="mm-btn-cancel">Cancel</Button>
                                    <Button type="primary" htmlType="submit" loading={createPromotion.isPending || updatePromotion.isPending} className="mm-btn-primary">
                                        <SaveOutlined /> Save
                                    </Button>
                                </Space>
                            </div>
                        </Form>
                    </div>
                </Modal>

                {/* ============================================================
                    RECIPE MODAL
                ============================================================ */}
                <Modal
                    title={
                        <div className="mm-modal-header-clean">
                            <div className="mm-modal-title-icon"><BoxPlotOutlined /></div>
                            <div className="mm-modal-title-text">Recipe Details</div>
                            <div className="mm-modal-badge">{selectedItem?.name || 'Menu Item'}</div>
                        </div>
                    }
                    open={recipeModalVisible}
                    onCancel={() => setRecipeModalVisible(false)}
                    width={700}
                    className="mm-modal-clean"
                    footer={
                        <div className="mm-modal-footer-enhanced">
                            <Button type="primary" onClick={() => setRecipeModalVisible(false)} className="mm-btn-primary">Close</Button>
                        </div>
                    }
                    maskClosable={false}
                    keyboard={false}
                    bodyStyle={{ padding: 0, maxHeight: 'none', overflow: 'visible' }}
                >
                    {selectedItem && (
                        <div className="mm-modal-clean-content">
                            <Descriptions column={2} bordered size="small" className="mm-recipe-descriptions">
                                <Descriptions.Item label="ID">{selectedItem.id}</Descriptions.Item>
                                <Descriptions.Item label="Category"><Tag color="blue">{selectedItem.category}</Tag></Descriptions.Item>
                                <Descriptions.Item label="Price"><span className="mm-item-price">{currency(selectedItem.price)}</span></Descriptions.Item>
                                <Descriptions.Item label="Prep Time">{selectedItem.prep_time_minutes ? `${selectedItem.prep_time_minutes} min` : '—'}</Descriptions.Item>
                            </Descriptions>
                            <Divider orientation="left" className="mm-divider-enhanced">Description</Divider>
                            <p className="mm-recipe-description">{selectedItem.description || 'No description.'}</p>
                            <Divider orientation="left" className="mm-divider-enhanced">Recipe Ingredients</Divider>
                            {selectedItem.recipe_ingredients?.length ? (
                                <Table 
                                    rowKey="id" 
                                    size="small" 
                                    pagination={false} 
                                    dataSource={selectedItem.recipe_ingredients} 
                                    className="mm-recipe-table"
                                    columns={[
                                        { title: 'Ingredient', render: (_, row) => row.name || row.ingredient?.name || '—' },
                                        { title: 'Qty / Pax', render: (_, row) => `${row.quantity_per_pax} ${row.unit}` },
                                        { title: 'Unit Cost', render: (_, row) => currency(row.unit_cost || row.ingredient?.unit_cost) }
                                    ]} 
                                />
                            ) : (
                                <Empty description="No recipe ingredients saved" image={Empty.PRESENTED_IMAGE_SIMPLE} className="mm-empty-state" />
                            )}
                            <Divider orientation="left" className="mm-divider-enhanced">Dietary</Divider>
                            <Space wrap>
                                {selectedItem.is_vegetarian && <Tag color="green" className="mm-dietary-tag">Vegetarian</Tag>}
                                {selectedItem.is_vegan && <Tag color="lime" className="mm-dietary-tag">Vegan</Tag>}
                                {selectedItem.is_gluten_free && <Tag color="gold" className="mm-dietary-tag">Gluten Free</Tag>}
                                {selectedItem.is_halal && <Tag color="cyan" className="mm-dietary-tag">Halal</Tag>}
                            </Space>
                            <Divider orientation="left" className="mm-divider-enhanced">Rating</Divider>
                            <Rate disabled value={selectedItem.rating || 0} allowHalf className="mm-rating-display" />
                        </div>
                    )}
                </Modal>
            </div>
        </ConfigProvider>
    );
};

export default MenuManagement;