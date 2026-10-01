// src/features/inventory/pages/Inventory.jsx
import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { 
  FiPackage, FiSearch, FiFilter, FiPlus, FiEdit2, FiTrash2, FiDownload,
  FiRefreshCw, FiChevronLeft, FiChevronRight, FiEye, FiAlertCircle,
  FiCheckCircle, FiXCircle, FiClock, FiTrendingUp, FiTrendingDown,
  FiGrid, FiList, FiTag, FiBox, FiMapPin, FiShoppingBag, FiCalendar,
  FiUser, FiSettings, FiArchive, FiTool, FiLayers, FiPrinter, FiTruck,  
  FiInfo, FiCheck, FiAlertTriangle, FiBell, FiBarChart2, FiFileText,
  FiSliders, FiDroplet, FiThermometer, FiShield, FiZap, FiHeart,
  FiStar
} from 'react-icons/fi';
import { AiFillProduct } from "react-icons/ai";
import {
  BsArrowReturnLeft, BsCartPlus, BsCartDash, BsCalendarEvent,
  BsGeoAlt, BsTelephone, BsFileText, BsCalculator, BsGraphUp,
  BsClipboardData, BsBuilding, BsPersonBadge, BsBoxSeam
} from 'react-icons/bs';
import { MdHistory, MdWarning, MdRestaurant, MdKitchen } from 'react-icons/md';
import { GiSpoon, GiChicken, GiCarrot, GiSaltShaker, GiOilDrum, GiMeat, GiGrain } from 'react-icons/gi';
import { message, Modal } from 'antd';
import api from '../../../services/api';
import '../styles/Inventory.css';

// ============================================================
// CACHE KEYS
// ============================================================
const CACHE_KEYS = {
  PRODUCTS: 'inventory_products',
  EQUIPMENT: 'inventory_equipment',
  MOVEMENTS: 'inventory_movements',
  PURCHASE_REQUESTS: 'inventory_purchase_requests',
  WASTE_RECORDS: 'inventory_waste_records',
  SUPPLIERS: 'inventory_suppliers',
  EQUIPMENT_RESERVATIONS: 'inventory_equipment_reservations',
  STATS: 'inventory_stats',
  PRODUCTS_ARCHIVED: 'inventory_products_archived',
  EQUIPMENT_ARCHIVED: 'inventory_equipment_archived',
};

// ============================================================
// CACHE HELPER
// ============================================================
const memoryCache = {};

const cache = {
  get: (key) => {
    if (memoryCache[key]) {
      return memoryCache[key];
    }
    try {
      const item = localStorage.getItem(key);
      if (!item) return null;
      const { data, timestamp } = JSON.parse(item);
      if (Date.now() - timestamp > 5 * 60 * 1000) {
        localStorage.removeItem(key);
        delete memoryCache[key];
        return null;
      }
      memoryCache[key] = data;
      return data;
    } catch {
      return null;
    }
  },
  set: (key, data) => {
    memoryCache[key] = data;
    try {
      localStorage.setItem(key, JSON.stringify({ data, timestamp: Date.now() }));
    } catch (e) {}
  },
  invalidate: (key) => {
    delete memoryCache[key];
    localStorage.removeItem(key);
  },
  invalidateAll: () => {
    Object.values(CACHE_KEYS).forEach(key => {
      delete memoryCache[key];
      localStorage.removeItem(key);
    });
  }
};

// ============================================================
// MAIN COMPONENT
// ============================================================
const Inventory = () => {
  // Module state
  const [activeModule, setActiveModule] = useState('products');
  const [activeProductSubModule, setActiveProductSubModule] = useState('ingredients');
  const [activeEquipmentSubModule, setActiveEquipmentSubModule] = useState('equipment-list');
  const [viewMode, setViewMode] = useState('table');
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [selectedItems, setSelectedItems] = useState([]);
  const [showFilters, setShowFilters] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedItem, setSelectedItem] = useState(null);
  const [showItemDetails, setShowItemDetails] = useState(false);
  const [showAddProductModal, setShowAddProductModal] = useState(false);
  const [showAddEquipmentModal, setShowAddEquipmentModal] = useState(false);
  const [showEditModal, setShowEditModal] = useState(false);
  const [editingItem, setEditingItem] = useState(null);
  const [showPurchaseRequestModal, setShowPurchaseRequestModal] = useState(false);
  const [showWasteRecordModal, setShowWasteRecordModal] = useState(false);
  const [showEquipmentReservationModal, setShowEquipmentReservationModal] = useState(false);
  const [showStockMovementModal, setShowStockMovementModal] = useState(false);
  
  // Data states
  const [products, setProducts] = useState(() => cache.get(CACHE_KEYS.PRODUCTS) || []);
  const [equipment, setEquipment] = useState(() => cache.get(CACHE_KEYS.EQUIPMENT) || []);
  const [purchaseRequests, setPurchaseRequests] = useState(() => cache.get(CACHE_KEYS.PURCHASE_REQUESTS) || []);
  const [wasteRecords, setWasteRecords] = useState(() => cache.get(CACHE_KEYS.WASTE_RECORDS) || []);
  const [suppliers, setSuppliers] = useState(() => cache.get(CACHE_KEYS.SUPPLIERS) || []);
  const [equipmentReservations, setEquipmentReservations] = useState(() => cache.get(CACHE_KEYS.EQUIPMENT_RESERVATIONS) || []);
  const [inventoryMovements, setInventoryMovements] = useState(() => cache.get(CACHE_KEYS.MOVEMENTS) || []);
  const [stockAlerts, setStockAlerts] = useState([]);
  
  const [isInitialLoading, setIsInitialLoading] = useState(true);
  const [error, setError] = useState(null);
  
  // Stock editing state
  const [editingStock, setEditingStock] = useState(null);
  const [editingStockValue, setEditingStockValue] = useState('');
  
  // Stats state
  const [stats, setStats] = useState(() => {
    const cached = cache.get(CACHE_KEYS.STATS);
    return cached || {
      products: { total: 0, lowStock: 0, outOfStock: 0, expiring: 0, totalQuantity: 0, reserved: 0 },
      equipment: { total: 0, inUse: 0, available: 0, damaged: 0, missing: 0, totalQuantity: 0, reserved: 0 },
    };
  });

  const searchTimeout = useRef(null);
  const containerRef = useRef(null);
  const ITEMS_PER_PAGE = 5;
  const [showActive, setShowActive] = useState(true);
  const [showInactive, setShowInactive] = useState(false);
  const [currentDateTime, setCurrentDateTime] = useState(new Date());
  const [notifications, setNotifications] = useState([]);
  const [showNotifications, setShowNotifications] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);

  // ============================================================
  // SUB-NAVIGATION ITEMS
  // ============================================================
  const productSubNavItems = [
    { id: 'ingredients', name: 'Ingredients', icon: <FiPackage /> },
    { id: 'stock-levels', name: 'Stock Levels', icon: <FiBarChart2 /> },
    { id: 'movements', name: 'Movements', icon: <BsCartPlus /> },
    { id: 'computation', name: 'Computation', icon: <BsCalculator /> },
    { id: 'purchase-requests', name: 'Purchase Requests', icon: <FiShoppingBag /> },
    { id: 'suppliers', name: 'Suppliers', icon: <FiTruck /> },
    { id: 'waste', name: 'Waste & Spoilage', icon: <FiTrash2 /> },
  ];

  const equipmentSubNavItems = [
    { id: 'equipment-list', name: 'Equipment List', icon: <AiFillProduct /> },
    { id: 'equipment-reservations', name: 'Reservations', icon: <FiClock /> },
    { id: 'equipment-maintenance', name: 'Maintenance', icon: <FiTool /> },
  ];

  // ============================================================
  // CATEGORIES
  // ============================================================
  const categories = useMemo(() => [
    { id: 'GRN', name: 'Grains', type: 'product', icon: '🌾' },
    { id: 'MET', name: 'Meat', type: 'product', icon: '🥩' },
    { id: 'VEG', name: 'Vegetables', type: 'product', icon: '🥕' },
    { id: 'FRZ', name: 'Frozen Products', type: 'product', icon: '❄️' },
    { id: 'DRK', name: 'Drinks', type: 'product', icon: '🥤' },
    { id: 'SAU', name: 'Sauces', type: 'product', icon: '🥫' },
    { id: 'DAI', name: 'Dairy', type: 'product', icon: '🥛' },
    { id: 'BAK', name: 'Bakery', type: 'product', icon: '🥖' },
    { id: 'OIL', name: 'Oils & Fats', type: 'product', icon: '🫒' },
    { id: 'SPC', name: 'Spices & Seasonings', type: 'product', icon: '🧂' },
  ], []);

  const equipmentMainCategories = useMemo(() => [
    'Eating Utensils', 'Tableware', 'Serving Equipment', 'Cooking Equipment',
    'Food Storage', 'Furniture', 'Cleaning & Hygiene', 'Extra Essentials'
  ], []);

  const ingredientTypes = {
    direct: { name: 'Direct Ingredients', icon: <GiMeat />, color: '#10b981', bgColor: 'rgba(16, 185, 129, 0.1)' },
    reusable: { name: 'Reusable Ingredients', icon: <GiOilDrum />, color: '#f59e0b', bgColor: 'rgba(245, 158, 11, 0.1)' },
    estimated: { name: 'Estimated Ingredients', icon: <GiSaltShaker />, color: '#8b5cf6', bgColor: 'rgba(139, 92, 246, 0.1)' }
  };

  // ============================================================
  // FORM STATES
  // ============================================================
  const [newProduct, setNewProduct] = useState({
    name: '', sku: '', category: '', ingredientType: 'direct', quantity: '', unit: 'kg',
    minStock: '', maxStock: '', reorderPoint: '', location: '', supplier: '', expiryDate: '',
    leadTime: '', costPerUnit: '', yieldPercentage: 100, reuseFactor: 1, notes: ''
  });

  const [editProduct, setEditProduct] = useState({
    name: '', sku: '', category: '', ingredientType: 'direct', quantity: '', unit: 'kg',
    minStock: '', maxStock: '', reorderPoint: '', location: '', supplier: '', expiryDate: '',
    leadTime: '', costPerUnit: '', yieldPercentage: 100, reuseFactor: 1, notes: '', active: true
  });

  const [newEquipment, setNewEquipment] = useState({
    name: '', category: '', sub_category: '', total_quantity: '', location: '', supplier: '',
    last_maintenance: '', condition: 'Good', notes: '', model: '', serial_number: ''
  });

  const [editEquipment, setEditEquipment] = useState({
    name: '', category: '', sub_category: '', total_quantity: '', location: '', supplier: '',
    last_maintenance: '', condition: 'Good', notes: '', model: '', serial_number: '', active: true
  });

  const [stockMovement, setStockMovement] = useState({
    type: 'purchase', quantity: '', reason: '', reference: '', date: new Date().toISOString().split('T')[0]
  });

  const [purchaseRequest, setPurchaseRequest] = useState({
    ingredientId: '', quantity: '', supplierId: '', urgency: 'normal', notes: '', expectedDelivery: ''
  });

  const [wasteRecord, setWasteRecord] = useState({
    ingredientId: '', quantity: '', reason: 'spoilage', notes: ''
  });

  const [equipmentReservation, setEquipmentReservation] = useState({
    equipmentId: '', quantity: '', eventId: '', startDate: '', endDate: '', notes: ''
  });

  // ============================================================
  // HELPER FUNCTIONS
  // ============================================================
  const getStatusDetails = (status) => {
    const statusMap = {
      'in-stock': { color: '#10b981', icon: FiCheckCircle, text: 'In Stock', bg: 'rgba(16, 185, 129, 0.1)' },
      'low-stock': { color: '#f59e0b', icon: FiAlertCircle, text: 'Low Stock', bg: 'rgba(245, 158, 11, 0.1)' },
      'critical': { color: '#ef4444', icon: FiAlertTriangle, text: 'Critical', bg: 'rgba(239, 68, 68, 0.1)' },
      'out-of-stock': { color: '#6b7280', icon: FiXCircle, text: 'Out of Stock', bg: 'rgba(107, 114, 128, 0.1)' },
      'over-stock': { color: '#8b5cf6', icon: FiTrendingUp, text: 'Over Stock', bg: 'rgba(139, 92, 246, 0.1)' },
      'available': { color: '#10b981', icon: FiCheckCircle, text: 'Available', bg: 'rgba(16, 185, 129, 0.1)' },
      'in-use': { color: '#f59e0b', icon: FiClock, text: 'In Use', bg: 'rgba(245, 158, 11, 0.1)' },
    };
    return statusMap[status] || statusMap['in-stock'];
  };

  const getStockStatus = (current, reorderPoint, minStock) => {
    if (current <= 0) return 'out-of-stock';
    if (current <= (minStock || 5)) return 'critical';
    if (current <= reorderPoint) return 'low-stock';
    if (current >= 100) return 'over-stock';
    return 'in-stock';
  };

  const isExpiringSoon = (expiryDate) => {
    if (!expiryDate) return false;
    const days = (new Date(expiryDate) - new Date()) / (1000 * 60 * 60 * 24);
    return days <= 7 && days > 0;
  };

  const addNotification = (message, type = 'info') => {
    const newNotification = { id: Date.now(), message, time: 'Just now', read: false, type };
    setNotifications([newNotification, ...notifications]);
  };

  const markAsRead = (id) => {
    setNotifications(notifications.map(n => n.id === id ? { ...n, read: true } : n));
  };

  const markAllAsRead = () => {
    setNotifications(notifications.map(n => ({ ...n, read: true })));
  };

  const getSubCategoriesByMain = (mainCategory) => {
    const subCategories = {
      'Eating Utensils': [
        { id: 'cutlery', name: 'Cutlery (Forks, Knives, Spoons)', icon: '🍴' },
        { id: 'chopsticks', name: 'Chopsticks', icon: '🥢' },
        { id: 'straws', name: 'Straws & Stirrers', icon: '🥤' },
      ],
      'Tableware': [
        { id: 'plates', name: 'Plates & Saucers', icon: '🍽️' },
        { id: 'bowls', name: 'Bowls', icon: '🥣' },
        { id: 'cups', name: 'Cups & Glasses', icon: '🥛' },
        { id: 'mugs', name: 'Mugs', icon: '☕' },
      ],
      'Serving Equipment': [
        { id: 'platters', name: 'Serving Platters', icon: '🍽️' },
        { id: 'bowls', name: 'Serving Bowls', icon: '🥗' },
        { id: 'trays', name: 'Serving Trays', icon: '📋' },
        { id: 'utensils', name: 'Serving Utensils', icon: '🥄' },
      ],
      'Cooking Equipment': [
        { id: 'pots', name: 'Pots & Pans', icon: '🍳' },
        { id: 'knives', name: 'Knives & Cutting Boards', icon: '🔪' },
        { id: 'mixers', name: 'Mixers & Blenders', icon: '⚡' },
        { id: 'ovens', name: 'Ovens & Stoves', icon: '🔥' },
      ],
      'Food Storage': [
        { id: 'containers', name: 'Containers', icon: '📦' },
        { id: 'wrap', name: 'Wrap & Foil', icon: '🎁' },
        { id: 'labels', name: 'Labels & Markers', icon: '🏷️' },
      ],
      'Furniture': [
        { id: 'tables', name: 'Tables', icon: '🪑' },
        { id: 'chairs', name: 'Chairs', icon: '🪑' },
        { id: 'shelves', name: 'Shelves & Racks', icon: '📚' },
      ],
      'Cleaning & Hygiene': [
        { id: 'cleaning', name: 'Cleaning Supplies', icon: '🧹' },
        { id: 'sanitizers', name: 'Sanitizers', icon: '🧴' },
        { id: 'gloves', name: 'Gloves & PPE', icon: '🧤' },
      ],
      'Extra Essentials': [
        { id: 'decor', name: 'Decorations', icon: '🎨' },
        { id: 'lighting', name: 'Lighting', icon: '💡' },
        { id: 'sound', name: 'Sound Equipment', icon: '🔊' },
      ],
    };
    return subCategories[mainCategory] || [];
  };

  const getAutoPurchaseSuggestions = () => {
    return products
      .filter(p => (p.quantity || 0) <= (p.reorder_point || 15))
      .map(p => ({
        productId: p.product_id,
        name: p.name,
        currentStock: p.quantity || 0,
        reorderPoint: p.reorder_point || 15,
        suggestedQuantity: Math.ceil((p.max_stock || 100) - (p.quantity || 0)),
        unit: p.unit || 'kg'
      }));
  };

  // ============================================================
  // TIME UPDATE
  // ============================================================
  useEffect(() => {
    const timer = setInterval(() => setCurrentDateTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    setUnreadCount(notifications.filter(n => !n.read).length);
  }, [notifications]);

  // ============================================================
  // FETCH FUNCTIONS
  // ============================================================

  const fetchProductsData = useCallback(async (force = false) => {
    const cacheKey = showActive ? CACHE_KEYS.PRODUCTS : CACHE_KEYS.PRODUCTS_ARCHIVED;
    
    if (!force) {
      const cached = cache.get(cacheKey);
      if (cached) {
        setProducts(cached);
        return cached;
      }
    }
    
    try {
      const response = await api.get('/ingredients', {
        params: { 
          search: searchTerm || undefined, 
          per_page: 100,
          active: showActive,
          is_active: showActive,
        }
      });
      
      let data = response.data?.data?.data || response.data?.data || response.data || [];
      if (!Array.isArray(data)) data = [];
      
      const mappedProducts = data.map(p => ({
        ...p,
        product_id: p.ingredient_id || p.id,
        quantity: p.current_quantity || 0,
        reserved: p.reserved_quantity || 0,
        available: (p.current_quantity || 0) - (p.reserved_quantity || 0),
        status: getStockStatus(p.current_quantity || 0, p.reorder_point || 15, p.minimum_quantity || 10),
        ingredient_type: p.ingredient_type || 'direct',
        unit: p.unit || 'kg',
        min_stock: p.minimum_quantity || 10,
        max_stock: p.maximum_quantity || 100,
        reorder_point: p.reorder_point || 15,
        cost_per_unit: p.unit_cost || 0,
        active: p.is_active !== false
      }));
      setProducts(mappedProducts);
      cache.set(cacheKey, mappedProducts);
      return mappedProducts;
    } catch (error) {
      console.error('Failed to fetch products:', error);
      return [];
    }
  }, [searchTerm, showActive]);

  const fetchEquipmentData = useCallback(async (force = false) => {
    const cacheKey = showActive ? CACHE_KEYS.EQUIPMENT : CACHE_KEYS.EQUIPMENT_ARCHIVED;
    
    if (!force) {
      const cached = cache.get(cacheKey);
      if (cached && cached.length > 0) {
        setEquipment(cached);
        return cached;
      }
    }
    
    try {
      console.log('Fetching equipment data...');
      const response = await api.get('/equipment', {
        params: { 
          search: searchTerm || undefined, 
          per_page: 100,
          active: showActive ? 1 : 0,
          is_active: showActive ? 1 : 0,
        }
      });
      
      console.log('Equipment API Response:', response.data);
      
      let data = response.data?.data?.data || response.data?.data || response.data || [];
      if (!Array.isArray(data)) data = [];
      
      const mappedEquipment = data.map(e => {
        const equipmentId = e.equipment_id || e.id || e.equipmentId || `eq-${Math.random()}`;
        const totalQty = parseInt(e.total_quantity) || 0;
        const inUse = parseInt(e.in_use || e.in_use_quantity || 0);
        const reserved = parseInt(e.reserved || e.reserved_quantity || 0);
        const damaged = parseInt(e.damaged || e.damaged_quantity || 0);
        const missing = parseInt(e.missing || e.missing_quantity || 0);
        const available = Math.max(0, totalQty - inUse - reserved - damaged - missing);
        
        return {
          ...e,
          equipment_id: equipmentId,
          id: equipmentId,
          total_quantity: totalQty,
          in_use: inUse,
          in_use_quantity: inUse,
          reserved: reserved,
          reserved_quantity: reserved,
          damaged: damaged,
          damaged_quantity: damaged,
          missing: missing,
          missing_quantity: missing,
          available: available,
          available_quantity: available,
          status: totalQty > 0 && inUse >= totalQty ? 'in-use' : 'available',
          active: e.is_active !== false,
          is_active: e.is_active !== false,
          condition: e.condition || 'Good',
          description: e.description || e.notes || '',
          supplier: e.supplier?.name || e.supplier_name || e.supplier || '',
          supplier_id: e.supplier_id || null,
          last_maintenance: e.last_maintenance || null,
          code: e.code || '',
          model: e.model || '',
          serial_number: e.serial_number || '',
          location: e.location || '',
          category: e.category || '',
          sub_category: e.sub_category || '',
          name: e.name || 'Unnamed Equipment',
        };
      });
      
      console.log('Mapped Equipment:', mappedEquipment);
      
      setEquipment(mappedEquipment);
      cache.set(cacheKey, mappedEquipment);
      return mappedEquipment;
      
    } catch (error) {
      console.error('Failed to fetch equipment:', error);
      setError('Failed to load equipment data. Please refresh.');
      return [];
    }
  }, [searchTerm, showActive]);

  const fetchMovementsData = useCallback(async (force = false) => {
    if (!force) {
      const cached = cache.get(CACHE_KEYS.MOVEMENTS);
      if (cached) {
        setInventoryMovements(cached);
        return cached;
      }
    }
    
    try {
      const response = await api.get('/inventory/movements', { 
        params: { per_page: 100 } 
      });
      
      let data = response.data?.data?.data || response.data?.data || response.data || [];
      if (!Array.isArray(data)) data = [];
      
      const mapped = data.map(m => ({
        id: m.movement_id || m.id,
        ingredient: m.ingredient?.name || m.ingredient_name || 'Unknown',
        ingredient_id: m.ingredient_id,
        type: m.movement_type || m.type || 'adjustment',
        quantity: m.quantity_change || m.quantity || 0,
        unit: m.ingredient?.unit || m.unit || '',
        date: m.created_at || m.date || new Date().toISOString(),
        updatedBy: m.performed_by?.name || m.performed_by_name || m.updated_by || 'System',
        reason: m.reason || '',
        quantity_before: m.quantity_before || 0,
        quantity_after: m.quantity_after || 0,
      }));
      
      setInventoryMovements(mapped);
      cache.set(CACHE_KEYS.MOVEMENTS, mapped);
      return mapped;
      
    } catch (error) {
      console.error('Failed to fetch movements:', error);
      return [];
    }
  }, []);

 const fetchPurchaseRequestsData = useCallback(async (force = false) => {
    if (!force) {
      const cached = cache.get(CACHE_KEYS.PURCHASE_REQUESTS);
      if (cached) {
        setPurchaseRequests(cached);
        return cached;
      }
    }
    
    try {
      const response = await api.get('/inventory/purchase-requests', { params: { per_page: 100 } });
      let data = response.data?.data?.data || response.data?.data || [];
      if (!Array.isArray(data)) data = [];
      
      // ✅ FIX: Map nested objects to strings
      const mapped = data.map(r => ({
        ...r,
        id: r.purchase_request_id || r.id || r.request_id,
        ingredient: typeof r.ingredient === 'string' ? r.ingredient : (r.ingredient?.name || r.ingredient_name || 'Unknown'),
        supplier: typeof r.supplier === 'string' ? r.supplier : (r.supplier?.name || r.supplier_name || 'Unknown'),
        quantity: r.quantity || r.requested_quantity || 0,
        urgency: r.urgency || 'normal',
        status: r.status || 'pending',
        dateRequested: r.created_at || r.date_requested || r.date || new Date().toISOString(),
      }));
      
      setPurchaseRequests(mapped);
      cache.set(CACHE_KEYS.PURCHASE_REQUESTS, mapped);
      return mapped;
    } catch (error) {
      console.error('Failed to fetch purchase requests:', error);
      return [];
    }
  }, []);

  const fetchWasteData = useCallback(async (force = false) => {
    if (!force) {
      const cached = cache.get(CACHE_KEYS.WASTE_RECORDS);
      if (cached) {
        setWasteRecords(cached);
        return cached;
      }
    }
    
    try {
      const response = await api.get('/inventory/waste', { params: { per_page: 100 } });
      let data = response.data?.data?.data || response.data?.data || [];
      if (!Array.isArray(data)) data = [];
      
      // ✅ FIX: Map nested objects to strings
      const mapped = data.map(w => ({
        ...w,
        id: w.waste_record_id || w.id,
        ingredient: typeof w.ingredient === 'string' ? w.ingredient : (w.ingredient?.name || w.ingredient_name || 'Unknown'),
        quantity: w.quantity || w.quantity_wasted || 0,
        reason: w.reason || 'spoilage',
        date: w.date || w.created_at || w.waste_date,
        recordedBy: w.recorded_by?.name || w.recorder?.name || w.recordedBy || 'System',
      }));
      
      setWasteRecords(mapped);
      cache.set(CACHE_KEYS.WASTE_RECORDS, mapped);
      return mapped;
    } catch (error) {
      console.error('Failed to fetch waste records:', error);
      return [];
    }
  }, []);

  const fetchSuppliersData = useCallback(async (force = false) => {
    if (!force) {
      const cached = cache.get(CACHE_KEYS.SUPPLIERS);
      if (cached) {
        setSuppliers(cached);
        return cached;
      }
    }
    
    try {
      const response = await api.get('/suppliers', { params: { per_page: 100 } });
      let data = response.data?.data?.data || response.data?.data || [];
      if (!Array.isArray(data)) data = [];
      setSuppliers(data);
      cache.set(CACHE_KEYS.SUPPLIERS, data);
      return data;
    } catch (error) {
      console.error('Failed to fetch suppliers:', error);
      return [];
    }
  }, []);


  const fetchStatsData = useCallback(async (force = false) => {
  if (!force) {
    const cached = cache.get(CACHE_KEYS.STATS);
    if (cached) {
      setStats(cached);
      return cached;
    }
  }
  
  try {
    const response = await api.get('/inventory/dashboard-stats');
    const payload = response.data?.data || {};
    const productStats = payload.products || {};
    const equipmentStats = payload.equipment || {};
    
    const newStats = {
      products: {
        total: productStats.total_items || 0,
        totalQuantity: productStats.total_quantity || 0,
        lowStock: productStats.low_stock || 0,
        outOfStock: productStats.out_of_stock || 0,
        expiring: productStats.expiring_soon || 0,
        reserved: productStats.reserved || 0
      },
      equipment: {
        total: equipmentStats.total_items || 0,
        totalQuantity: equipmentStats.total_quantity || 0,
        inUse: equipmentStats.in_use || 0,
        available: equipmentStats.available || 0,
        damaged: equipmentStats.damaged || 0,
        missing: equipmentStats.missing || 0,
        reserved: equipmentStats.reserved || 0
      }
    };
    
    setStats(newStats);
    cache.set(CACHE_KEYS.STATS, newStats);
    return newStats;
    
  } catch (error) {
    console.error('Failed to fetch stats:', error);
    // Return existing stats if available
    return stats;
  }
}, []);


 const fetchReservationsData = useCallback(async (force = false) => {
    if (!force) {
      const cached = cache.get(CACHE_KEYS.EQUIPMENT_RESERVATIONS);
      if (cached) {
        setEquipmentReservations(cached);
        return cached;
      }
    }
    
    try {
      const response = await api.get('/equipment/reservations', { params: { per_page: 100 } });
      let data = response.data?.data?.data || response.data?.data || [];
      if (!Array.isArray(data)) data = [];
      
      // ✅ FIX: Map nested objects to strings
      const mapped = data.map(r => ({
        ...r,
        id: r.booking_equipment_id || r.id || r.reservation_id,
        equipment_name: typeof r.equipment === 'string' ? r.equipment : (r.equipment?.name || r.equipment_name || 'Unknown'),
        quantity_reserved: r.quantity_reserved || r.quantity || 0,
        booking_id: r.booking_id || r.event_id || r.eventId,
        rental_start_date: r.rental_start_date || r.start_date || r.startDate,
        rental_end_date: r.rental_end_date || r.end_date || r.endDate,
        status: r.status || 'pending',
        reservedBy: r.reserved_by?.name || r.reserver?.name || r.reservedBy || 'System',
      }));
      
      setEquipmentReservations(mapped);
      cache.set(CACHE_KEYS.EQUIPMENT_RESERVATIONS, mapped);
      return mapped;
    } catch (error) {
      console.error('Failed to fetch reservations:', error);
      return [];
    }
  }, []);

  // ============================================================
  // SILENT BACKGROUND LOAD
  // ============================================================

  const loadDataInBackground = useCallback(async (force = false) => {
    setError(null);
    
    try {
      const isProductsModule = activeModule === 'products';
      const isEquipmentModule = activeModule === 'equipment';
      
      const promises = [];

      const statsCached = cache.get(CACHE_KEYS.STATS);
      if (force || !statsCached) {
        promises.push(fetchStatsData(force));
      }

      if (isProductsModule) {
        const needsProducts = ['ingredients', 'stock-levels', 'computation'].includes(activeProductSubModule);
        if (needsProducts) {
          const cacheKey = showActive ? CACHE_KEYS.PRODUCTS : CACHE_KEYS.PRODUCTS_ARCHIVED;
          const cached = cache.get(cacheKey);
          if (force || !cached) {
            promises.push(fetchProductsData(force));
          }
        }
        if (activeProductSubModule === 'movements') {
          const cached = cache.get(CACHE_KEYS.MOVEMENTS);
          if (force || !cached) {
            promises.push(fetchMovementsData(force));
          }
        }
        if (activeProductSubModule === 'purchase-requests') {
          const cached = cache.get(CACHE_KEYS.PURCHASE_REQUESTS);
          if (force || !cached) {
            promises.push(fetchPurchaseRequestsData(force));
          }
        }
        if (activeProductSubModule === 'waste') {
          const cached = cache.get(CACHE_KEYS.WASTE_RECORDS);
          if (force || !cached) {
            promises.push(fetchWasteData(force));
          }
        }
        if (activeProductSubModule === 'suppliers') {
          const cached = cache.get(CACHE_KEYS.SUPPLIERS);
          if (force || !cached) {
            promises.push(fetchSuppliersData(force));
          }
        }
      }

      if (isEquipmentModule) {
        const needsEquipment = ['equipment-list', 'equipment-maintenance'].includes(activeEquipmentSubModule);
        if (needsEquipment) {
          const cacheKey = showActive ? CACHE_KEYS.EQUIPMENT : CACHE_KEYS.EQUIPMENT_ARCHIVED;
          const cached = cache.get(cacheKey);
          if (force || !cached || cached.length === 0) {
            promises.push(fetchEquipmentData(force));
          }
        }
        if (activeEquipmentSubModule === 'equipment-reservations') {
          const cached = cache.get(CACHE_KEYS.EQUIPMENT_RESERVATIONS);
          if (force || !cached) {
            promises.push(fetchReservationsData(force));
          }
        }
      }

      if (promises.length > 0) {
        await Promise.all(promises);
      }
    } catch (error) {
      console.error('Failed to load data:', error);
      setError('Failed to load inventory data');
    }
  }, [
    activeModule, 
    activeProductSubModule, 
    activeEquipmentSubModule,
    showActive,
    fetchProductsData,
    fetchEquipmentData,
    fetchMovementsData,
    fetchPurchaseRequestsData,
    fetchWasteData,
    fetchSuppliersData,
    fetchReservationsData,
    fetchStatsData,
  ]);

  // ============================================================
  // INITIAL LOAD
  // ============================================================

  useEffect(() => {
    const initLoad = async () => {
      await loadDataInBackground(false);
      setIsInitialLoading(false);
    };
    initLoad();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ============================================================
  // TAB CHANGE
  // ============================================================

  useEffect(() => {
    if (isInitialLoading) return;
    setCurrentPage(1);
    loadDataInBackground(false);
  }, [activeModule, activeProductSubModule, activeEquipmentSubModule, loadDataInBackground, isInitialLoading]);

  // ============================================================
  // SEARCH
  // ============================================================

  useEffect(() => {
    if (isInitialLoading) return;
    
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    searchTimeout.current = setTimeout(() => {
      if (activeModule === 'products') {
        cache.invalidate(showActive ? CACHE_KEYS.PRODUCTS : CACHE_KEYS.PRODUCTS_ARCHIVED);
      } else {
        cache.invalidate(showActive ? CACHE_KEYS.EQUIPMENT : CACHE_KEYS.EQUIPMENT_ARCHIVED);
      }
      loadDataInBackground(true);
    }, 500);
    
    return () => { if (searchTimeout.current) clearTimeout(searchTimeout.current); };
  }, [searchTerm, loadDataInBackground, isInitialLoading, activeModule, showActive]);

  // ============================================================
  // ARCHIVE TOGGLE
  // ============================================================

  const handleArchiveToggle = useCallback((active, inactive) => {
    setShowActive(active);
    setShowInactive(inactive);
    setCurrentPage(1);
    
    if (activeModule === 'products') {
      cache.invalidate(CACHE_KEYS.PRODUCTS);
      cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
    } else {
      cache.invalidate(CACHE_KEYS.EQUIPMENT);
      cache.invalidate(CACHE_KEYS.EQUIPMENT_ARCHIVED);
    }
    loadDataInBackground(true);
  }, [activeModule, loadDataInBackground]);

  // ============================================================
  // MANUAL REFRESH
  // ============================================================

  const handleRefresh = useCallback(() => {
    cache.invalidateAll();
    loadDataInBackground(true);
  }, [loadDataInBackground]);

  // ============================================================
  // GENERATE STOCK ALERTS
  // ============================================================

  useEffect(() => {
    if (!products.length) return;
    
    const alerts = [];
    products.forEach(product => {
      const stock = product.quantity || 0;
      const reorderPoint = product.reorder_point || 15;
      if (stock <= 0) {
        alerts.push({ id: `out-${product.product_id}`, type: 'danger', message: `${product.name} is OUT OF STOCK`, severity: 'danger', product });
      } else if (stock <= (product.min_stock || 10)) {
        alerts.push({ id: `critical-${product.product_id}`, type: 'danger', message: `${product.name} is at CRITICAL level (${stock} left)`, severity: 'danger', product });
      } else if (stock <= reorderPoint) {
        alerts.push({ id: `low-${product.product_id}`, type: 'warning', message: `${product.name} is LOW (${stock} left). Reorder recommended.`, severity: 'warning', product });
      }
      if (product.expiry_date && isExpiringSoon(product.expiry_date)) {
        alerts.push({ id: `expiry-${product.product_id}`, type: 'warning', message: `${product.name} expires soon (${product.expiry_date})`, severity: 'warning', product });
      }
    });
    setStockAlerts(alerts);
  }, [products]);

  // ============================================================
  // QUICK STOCK UPDATE
  // ============================================================


const handleQuickStockUpdate = async (productId, newQuantity) => {
  if (!productId || newQuantity === undefined || newQuantity === null) {
    message.warning('Please enter a valid quantity');
    return;
  }
  
  const quantity = parseFloat(newQuantity);
  if (isNaN(quantity) || quantity < 0) {
    message.error('Please enter a valid positive number');
    return;
  }
  
  try {
    // Get the current stock first to calculate the change
    const product = products.find(p => p.product_id === productId);
    const currentStock = product?.quantity || 0;
    const quantityChange = quantity - currentStock;
    
    // Update the stock
    await api.put(`/ingredients/${productId}/stock`, {
      current_stock: quantity
    });
    
    // If there's a change, record a movement
    if (quantityChange !== 0) {
      const movementType = quantityChange > 0 ? 'purchase' : 'usage';
      await api.post('/inventory/movements', {
        ingredient_id: productId,
        quantity_change: quantityChange,
        movement_type: movementType,
        reason: `Manual stock adjustment: ${currentStock} → ${quantity}`
      });
    }
    
    message.success('Stock updated successfully');
    
    setEditingStock(null);
    setEditingStockValue('');
    
    cache.invalidate(CACHE_KEYS.PRODUCTS);
    cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
    cache.invalidate(CACHE_KEYS.STATS);
    cache.invalidate(CACHE_KEYS.MOVEMENTS);
    await loadDataInBackground(true);
  } catch (error) {
    console.error('Quick stock update error:', error);
    message.error(error.response?.data?.message || 'Failed to update stock');
  }
};


  // ============================================================
  // CRUD OPERATIONS
  // ============================================================

  const handleAddProduct = async () => {
    if (!newProduct.name) {
      addNotification('Please fill in all required fields', 'error');
      return;
    }
    try {
      await api.post('/ingredients', {
        name: newProduct.name,
        sku: newProduct.sku,
        category: newProduct.category,
        ingredient_type: newProduct.ingredientType,
        current_quantity: parseFloat(newProduct.quantity) || 0,
        unit: newProduct.unit,
        minimum_quantity: parseFloat(newProduct.minStock) || 10,
        maximum_quantity: parseFloat(newProduct.maxStock) || 100,
        reorder_point: parseInt(newProduct.reorderPoint) || 15,
        storage_location: newProduct.location,
        supplier: newProduct.supplier,
        expiry_date: newProduct.expiryDate,
        lead_time_days: parseInt(newProduct.leadTime) || 0,
        unit_cost: parseFloat(newProduct.costPerUnit) || 0,
        yield_percentage: parseInt(newProduct.yieldPercentage) || 100,
        reuse_factor: parseFloat(newProduct.reuseFactor) || 1,
        notes: newProduct.notes
      });
      message.success('Product added successfully');
      setShowAddProductModal(false);
      setNewProduct({ name: '', sku: '', category: '', ingredientType: 'direct', quantity: '', unit: 'kg', minStock: '', maxStock: '', reorderPoint: '', location: '', supplier: '', expiryDate: '', leadTime: '', costPerUnit: '', yieldPercentage: 100, reuseFactor: 1, notes: '' });
      
      cache.invalidate(CACHE_KEYS.PRODUCTS);
      cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
      cache.invalidate(CACHE_KEYS.STATS);
      await loadDataInBackground(true);
    } catch (error) {
      message.error(error.response?.data?.message || 'Failed to add product');
    }
  };

  const handleUpdateProduct = async () => {
    if (!editingItem) return;
    try {
      await api.put(`/ingredients/${editingItem.product_id}`, {
        name: editProduct.name,
        sku: editProduct.sku,
        category: editProduct.category,
        ingredient_type: editProduct.ingredientType,
        unit: editProduct.unit,
        minimum_quantity: parseFloat(editProduct.minStock) || 10,
        maximum_quantity: parseFloat(editProduct.maxStock) || 100,
        reorder_point: parseInt(editProduct.reorderPoint) || 15,
        storage_location: editProduct.location,
        supplier: editProduct.supplier,
        expiry_date: editProduct.expiryDate,
        lead_time_days: parseInt(editProduct.leadTime) || 0,
        unit_cost: parseFloat(editProduct.costPerUnit) || 0,
        yield_percentage: parseInt(editProduct.yieldPercentage) || 100,
        reuse_factor: parseFloat(editProduct.reuseFactor) || 1,
        notes: editProduct.notes,
        is_active: editProduct.active
      });
      message.success('Product updated successfully');
      setShowEditModal(false);
      setEditingItem(null);
      
      cache.invalidate(CACHE_KEYS.PRODUCTS);
      cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
      cache.invalidate(CACHE_KEYS.STATS);
      await loadDataInBackground(true);
    } catch (error) {
      message.error(error.response?.data?.message || 'Failed to update product');
    }
  };

  const handleDeleteProduct = async (id) => {
    Modal.confirm({
      title: 'Archive Ingredient',
      content: 'Are you sure you want to archive this ingredient?',
      onOk: async () => {
        try {
          await api.delete(`/ingredients/${id}`);
          message.success('Ingredient archived');
          cache.invalidate(CACHE_KEYS.PRODUCTS);
          cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
          cache.invalidate(CACHE_KEYS.STATS);
          await loadDataInBackground(true);
        } catch (error) {
          message.error('Failed to archive ingredient');
        }
      }
    });
  };

  const handleRestoreProduct = async (id) => {
    try {
      await api.post(`/ingredients/${id}/restore`);
      message.success('Ingredient restored');
      cache.invalidate(CACHE_KEYS.PRODUCTS);
      cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
      cache.invalidate(CACHE_KEYS.STATS);
      await loadDataInBackground(true);
    } catch (error) {
      message.error('Failed to restore ingredient');
    }
  };

  const handleAddEquipment = async () => {
    if (!newEquipment.name || !newEquipment.category) {
      addNotification('Please fill in all required fields', 'error');
      return;
    }
    try {
      const payload = {
        name: newEquipment.name,
        category: newEquipment.category,
        total_quantity: parseInt(newEquipment.total_quantity) || 0,
        location: newEquipment.location || null,
        condition: newEquipment.condition || 'Good',
        description: newEquipment.notes || '',
        model: newEquipment.model || null,
        serial_number: newEquipment.serial_number || null,
        last_maintenance: newEquipment.last_maintenance || null,
        is_active: true,
        supplier_id: newEquipment.supplier_id || null
      };
      
      await api.post('/equipment', payload);
      message.success('Equipment added successfully');
      
      setShowAddEquipmentModal(false);
      setNewEquipment({ 
        name: '', category: '', sub_category: '', total_quantity: '', 
        location: '', supplier: '', last_maintenance: '', condition: 'Good', 
        notes: '', model: '', serial_number: '' 
      });
      
      cache.invalidate(CACHE_KEYS.EQUIPMENT);
      cache.invalidate(CACHE_KEYS.EQUIPMENT_ARCHIVED);
      cache.invalidate(CACHE_KEYS.STATS);
      await loadDataInBackground(true);
      
    } catch (error) {
      console.error('Add equipment error:', error);
      message.error(error.response?.data?.message || 'Failed to add equipment');
    }
  };

  const handleUpdateEquipment = async () => {
    if (!editingItem) return;
    try {
      const payload = {
        name: editEquipment.name,
        category: editEquipment.category,
        total_quantity: parseInt(editEquipment.total_quantity) || 0,
        location: editEquipment.location || null,
        condition: editEquipment.condition || 'Good',
        description: editEquipment.notes || '',
        model: editEquipment.model || null,
        serial_number: editEquipment.serial_number || null,
        last_maintenance: editEquipment.last_maintenance || null,
        is_active: editEquipment.active !== false
      };
      
      await api.put(`/equipment/${editingItem.equipment_id}`, payload);
      message.success('Equipment updated successfully');
      
      setShowEditModal(false);
      setEditingItem(null);
      
      cache.invalidate(CACHE_KEYS.EQUIPMENT);
      cache.invalidate(CACHE_KEYS.EQUIPMENT_ARCHIVED);
      cache.invalidate(CACHE_KEYS.STATS);
      await loadDataInBackground(true);
      
    } catch (error) {
      console.error('Update equipment error:', error);
      message.error(error.response?.data?.message || 'Failed to update equipment');
    }
  };

  const handleDeleteEquipment = async (id) => {
    Modal.confirm({
      title: 'Archive Equipment',
      content: 'Are you sure you want to archive this equipment?',
      onOk: async () => {
        try {
          await api.delete(`/equipment/${id}`);
          message.success('Equipment archived successfully');
          
          cache.invalidate(CACHE_KEYS.EQUIPMENT);
          cache.invalidate(CACHE_KEYS.EQUIPMENT_ARCHIVED);
          cache.invalidate(CACHE_KEYS.STATS);
          await loadDataInBackground(true);
          
        } catch (error) {
          console.error('Delete equipment error:', error);
          message.error(error.response?.data?.message || 'Failed to archive equipment');
        }
      }
    });
  };

  const handleRestoreEquipment = async (id) => {
    try {
      await api.post(`/equipment/${id}/restore`);
      message.success('Equipment restored successfully');
      
      cache.invalidate(CACHE_KEYS.EQUIPMENT);
      cache.invalidate(CACHE_KEYS.EQUIPMENT_ARCHIVED);
      cache.invalidate(CACHE_KEYS.STATS);
      await loadDataInBackground(true);
      
    } catch (error) {
      console.error('Restore equipment error:', error);
      message.error(error.response?.data?.message || 'Failed to restore equipment');
    }
  };

  // ============================================================
  // STOCK MOVEMENT
  // ============================================================

 const handleStockMovement = async () => {
  if (!selectedItem || !stockMovement.quantity) {
    message.warning('Please select an item and enter quantity');
    return;
  }
  
  try {
    const quantity = parseFloat(stockMovement.quantity);
    if (isNaN(quantity) || quantity <= 0) {
      message.error('Please enter a valid positive quantity');
      return;
    }
    
    // Determine if it's a purchase (stock in) or usage (stock out)
    const isPurchase = stockMovement.type === 'purchase' || stockMovement.type === 'return';
    const quantityChange = isPurchase ? Math.abs(quantity) : -Math.abs(quantity);
    
    // Map the movement type to the correct enum value
    let movementType = stockMovement.type;
    if (movementType === 'deduction') movementType = 'usage';
    if (movementType === 'adjustment') movementType = 'adjustment';
    
    // Get the product's current stock
    const product = products.find(p => p.product_id === selectedItem.product_id);
    const currentStock = product?.quantity || 0;
    
    const payload = {
      ingredient_id: selectedItem.product_id || selectedItem.ingredient_id,
      quantity_change: quantityChange,
      movement_type: movementType,
      reason: stockMovement.reason || stockMovement.reference || `Manual ${movementType}`
    };
    
    console.log('Recording movement with payload:', payload);
    
    // Record the movement first
    await api.post('/inventory/movements', payload);
    
    // Then update the stock
    const newStock = Math.max(0, currentStock + quantityChange);
    await api.put(`/ingredients/${selectedItem.product_id}/stock`, {
      current_stock: newStock
    });
    
    message.success('Stock movement recorded successfully');
    setShowStockMovementModal(false);
    setStockMovement({ type: 'purchase', quantity: '', reason: '', reference: '', date: new Date().toISOString().split('T')[0] });
    
    cache.invalidate(CACHE_KEYS.MOVEMENTS);
    cache.invalidate(CACHE_KEYS.PRODUCTS);
    cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
    cache.invalidate(CACHE_KEYS.STATS);
    await loadDataInBackground(true);
  } catch (error) {
    console.error('Stock movement error:', error);
    message.error(error.response?.data?.message || 'Failed to record movement');
  }
};

  // ============================================================
  // WASTE RECORD
  // ============================================================
const handleRecordWaste = async () => {
  // Parse the ingredient ID as an integer
  const ingredientId = parseInt(wasteRecord.ingredientId);
  const quantity = parseFloat(wasteRecord.quantity);
  
  // Validate inputs
  if (!ingredientId || isNaN(ingredientId) || ingredientId <= 0) {
    message.warning('Please select a valid ingredient');
    return;
  }
  
  if (!quantity || isNaN(quantity) || quantity <= 0) {
    message.warning('Please enter a valid quantity');
    return;
  }
  
  try {
    const payload = {
      ingredient_id: ingredientId,  // Must be a number, NOT an object
      quantity: quantity,
      reason: wasteRecord.reason || 'spoilage',
      notes: wasteRecord.notes || ''
    };
    
    console.log('Recording waste with payload:', payload);
    
    await api.post('/inventory/waste', payload);
    message.success('Waste recorded successfully');
    
    // Reset form
    setShowWasteRecordModal(false);
    setWasteRecord({ 
      ingredientId: '', 
      quantity: '', 
      reason: 'spoilage', 
      notes: '' 
    });
    
    // Invalidate caches
    cache.invalidate(CACHE_KEYS.WASTE_RECORDS);
    cache.invalidate(CACHE_KEYS.PRODUCTS);
    cache.invalidate(CACHE_KEYS.PRODUCTS_ARCHIVED);
    cache.invalidate(CACHE_KEYS.STATS);
    await loadDataInBackground(true);
    
  } catch (error) {
    console.error('Waste recording error:', error);
    const errorMsg = error.response?.data?.message || 'Failed to record waste';
    message.error(errorMsg);
  }
};

  const handlePurchaseRequest = async () => {
    if (!purchaseRequest.ingredientId || !purchaseRequest.quantity) return;
    try {
      await api.post('/inventory/purchase-requests', {
        ingredient_id: purchaseRequest.ingredientId,
        quantity: parseFloat(purchaseRequest.quantity),
        supplier_id: purchaseRequest.supplierId,
        urgency: purchaseRequest.urgency,
        notes: purchaseRequest.notes,
        expected_delivery: purchaseRequest.expectedDelivery
      });
      message.success('Purchase request created');
      setShowPurchaseRequestModal(false);
      setPurchaseRequest({ ingredientId: '', quantity: '', supplierId: '', urgency: 'normal', notes: '', expectedDelivery: '' });
      
      cache.invalidate(CACHE_KEYS.PURCHASE_REQUESTS);
      await loadDataInBackground(true);
    } catch (error) {
      message.error('Failed to create purchase request');
    }
  };

  const handleEquipmentReservation = async () => {
    if (!equipmentReservation.equipmentId || !equipmentReservation.quantity) return;
    try {
      await api.post('/equipment/reservations', {
        equipment_id: equipmentReservation.equipmentId,
        quantity_reserved: parseInt(equipmentReservation.quantity),
        booking_id: equipmentReservation.eventId,
        rental_start_date: equipmentReservation.startDate,
        rental_end_date: equipmentReservation.endDate,
        notes: equipmentReservation.notes
      });
      message.success('Equipment reserved');
      setShowEquipmentReservationModal(false);
      setEquipmentReservation({ equipmentId: '', quantity: '', eventId: '', startDate: '', endDate: '', notes: '' });
      
      cache.invalidate(CACHE_KEYS.EQUIPMENT_RESERVATIONS);
      cache.invalidate(CACHE_KEYS.EQUIPMENT);
      cache.invalidate(CACHE_KEYS.STATS);
      await loadDataInBackground(true);
    } catch (error) {
      message.error('Failed to reserve equipment');
    }
  };

  const handleArchive = (itemId) => {
    if (activeModule === 'products') handleDeleteProduct(itemId);
    else handleDeleteEquipment(itemId);
  };

  const handleRestore = (itemId) => {
    if (activeModule === 'products') handleRestoreProduct(itemId);
    else handleRestoreEquipment(itemId);
  };

  const handleViewDetails = (item) => {
    setSelectedItem(item);
    setShowItemDetails(true);
  };

  const handleEdit = (item) => {
    setEditingItem(item);
    if (activeModule === 'products') {
      setEditProduct({
        name: item.name || '',
        sku: item.sku || '',
        category: item.category || '',
        ingredientType: item.ingredient_type || 'direct',
        quantity: item.quantity || '',
        unit: item.unit || 'kg',
        minStock: item.min_stock || '',
        maxStock: item.max_stock || '',
        reorderPoint: item.reorder_point || '',
        location: item.storage_location || '',
        supplier: item.supplier || '',
        expiryDate: item.expiry_date || '',
        leadTime: item.lead_time_days || '',
        costPerUnit: item.unit_cost || '',
        yieldPercentage: item.yield_percentage || 100,
        reuseFactor: item.reuse_factor || 1,
        notes: item.notes || '',
        active: item.active !== false
      });
    } else {
      setEditEquipment({
        name: item.name || '',
        category: item.category || '',
        sub_category: item.sub_category || '',
        total_quantity: item.total_quantity || '',
        location: item.location || '',
        supplier: item.supplier || '',
        last_maintenance: item.last_maintenance || '',
        condition: item.condition || 'Good',
        model: item.model || '',
        serial_number: item.serial_number || '',
        notes: item.description || '',
        active: item.active !== false
      });
    }
    setShowEditModal(true);
  };

  const handleUpdateItem = () => {
    if (activeModule === 'products') handleUpdateProduct();
    else handleUpdateEquipment();
  };

  // ============================================================
  // FILTER AND PAGINATION
  // ============================================================

  const getFilteredProducts = () => {
    let filtered = products;
    
    if (selectedCategory !== 'all') {
      if (['direct', 'reusable', 'estimated'].includes(selectedCategory)) {
        filtered = filtered.filter(p => p.ingredient_type === selectedCategory);
      } else if (['low-stock', 'out-of-stock', 'in-stock', 'critical', 'over-stock'].includes(selectedCategory)) {
        filtered = filtered.filter(p => p.status === selectedCategory);
      } else if (selectedCategory === 'expiring') {
        filtered = filtered.filter(p => isExpiringSoon(p.expiry_date));
      } else {
        filtered = filtered.filter(p => 
          p.category === selectedCategory || 
          p.category?.toLowerCase() === selectedCategory?.toLowerCase()
        );
      }
    }
    
    if (searchTerm) {
      const term = searchTerm.toLowerCase();
      filtered = filtered.filter(p => 
        p.name?.toLowerCase().includes(term) || 
        p.sku?.toLowerCase().includes(term) ||
        String(p.product_id || p.id).includes(term) ||
        p.category?.toLowerCase().includes(term)
      );
    }
    
    if (showActive && !showInactive) {
      filtered = filtered.filter(p => p.active !== false);
    } else if (!showActive && showInactive) {
      filtered = filtered.filter(p => p.active === false);
    }
    
    return filtered;
  };

  const getFilteredEquipment = () => {
    let filtered = equipment;
    
    if (!filtered || !Array.isArray(filtered)) {
      return [];
    }
    
    if (selectedCategory !== 'all') {
      filtered = filtered.filter(e => 
        (e.category && e.category === selectedCategory) || 
        (e.sub_category && e.sub_category === selectedCategory) ||
        (e.category && e.category.toLowerCase() === selectedCategory.toLowerCase())
      );
    }
    
    if (searchTerm) {
      const term = searchTerm.toLowerCase();
      filtered = filtered.filter(e => 
        (e.name && e.name.toLowerCase().includes(term)) ||
        String(e.equipment_id || e.id || '').includes(term) ||
        (e.category && e.category.toLowerCase().includes(term)) ||
        (e.sub_category && e.sub_category.toLowerCase().includes(term)) ||
        (e.model && e.model.toLowerCase().includes(term)) ||
        (e.serial_number && e.serial_number.toLowerCase().includes(term))
      );
    }
    
    if (showActive && !showInactive) {
      filtered = filtered.filter(e => e.active !== false);
    } else if (!showActive && showInactive) {
      filtered = filtered.filter(e => e.active === false);
    }
    
    return filtered;
  };

  const currentItems = useMemo(() => {
    if (activeModule === 'products') return getFilteredProducts();
    return getFilteredEquipment();
  }, [activeModule, products, equipment, selectedCategory, searchTerm, showActive, showInactive]);

  const totalPages = Math.max(1, Math.ceil(currentItems.length / ITEMS_PER_PAGE));
  const paginatedItems = currentItems.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

  useEffect(() => {
    if (currentPage > totalPages) setCurrentPage(1);
  }, [currentPage, totalPages]);

  useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, selectedCategory, activeModule]);

  // ============================================================
  // RENDER - SKELETON LOADING
  // ============================================================

  if (isInitialLoading) {
    return (
      <div className="inventory-container" ref={containerRef}>
        <div className="inventory-skeleton-header"></div>
        <div className="inventory-skeleton-stats">
          {[1,2,3,4].map(i => <div key={i} className="inventory-skeleton-card"></div>)}
        </div>
        <div className="inventory-skeleton-table"></div>
      </div>
    );
  }

  // ============================================================
  // MAIN RENDER
  // ============================================================

  return (
    <div className="inventory-container" ref={containerRef}>
      {/* Error Message */}
      {error && (
        <div className="inventory-error-banner">
          <FiAlertCircle />
          <span>{error}</span>
          <button onClick={handleRefresh}>Retry</button>
        </div>
      )}

      {/* Stock Alerts Bar */}
      {stockAlerts.length > 0 && (
        <motion.div 
          className="inventory-alerts-bar"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <div className="inventory-alerts-header">
            <FiAlertTriangle />
            <span>Stock Alerts</span>
            <span className="inventory-alerts-count">{stockAlerts.length}</span>
          </div>
          <div className="inventory-alerts-list">
            {stockAlerts.slice(0, 3).map(alert => (
              <div key={alert.id} className={`inventory-alert-item ${alert.severity}`}>
                {alert.severity === 'danger' ? <FiAlertCircle /> : <FiAlertTriangle />}
                <span>{alert.message}</span>
              </div>
            ))}
            {stockAlerts.length > 3 && (
              <div className="inventory-alert-more">+{stockAlerts.length - 3} more alerts</div>
            )}
          </div>
        </motion.div>
      )}

      {/* ============================================================
          HEADER
          ============================================================ */}
      <motion.div 
        className="inventory-header"
        initial={{ opacity: 0, y: -20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="inventory-header-left">
          <div className="inventory-header-icon">
            {activeModule === 'products' ? <FiPackage className="inventory-main-icon" /> : <AiFillProduct className="inventory-main-icon" />}
          </div>
          <div className="inventory-header-title">
            <h1>{activeModule === 'products' ? 'Product Inventory' : 'Equipment Inventory'}</h1>
            <p>{activeModule === 'products' ? 'Track ingredients, supplies, and stock levels' : 'Manage equipment, maintenance, and reservations'}</p>
          </div>
        </div>

        <div className="inventory-header-right">
          <div className="inventory-header-datetime">
            <div className="inventory-datetime-item">
              <FiCalendar className="inventory-datetime-icon" />
              <span className="inventory-datetime-value">
                {currentDateTime.toLocaleDateString('en-US', { 
                  month: 'short', day: 'numeric', year: 'numeric' 
                })}
              </span>
            </div>
            <div className="inventory-datetime-divider"></div>
            <div className="inventory-datetime-item">
              <FiClock className="inventory-datetime-icon" />
              <span className="inventory-datetime-value inventory-datetime-time">
                {currentDateTime.toLocaleTimeString('en-US', { 
                  hour: '2-digit', minute: '2-digit', hour12: true 
                })}
              </span>
            </div>
          </div>

          <div className="inventory-toggle-buttons">
            <motion.button
              className={`inventory-toggle-btn ${showActive ? 'active' : ''}`}
              onClick={() => handleArchiveToggle(true, false)}
              whileHover={{ scale: 1.05 }}
              whileTap={{ scale: 0.95 }}
            >
              <FiCheckCircle />
              <span>Active</span>
            </motion.button>
            <motion.button
              className={`inventory-toggle-btn ${showInactive ? 'active' : ''}`}
              onClick={() => handleArchiveToggle(false, true)}
              whileHover={{ scale: 1.05 }}
              whileTap={{ scale: 0.95 }}
            >
              <FiArchive />
              <span>Archived</span>
            </motion.button>
          </div>

          <motion.button
            className="inventory-refresh-btn"
            whileHover={{ scale: 1.05, rotate: 180 }}
            whileTap={{ scale: 0.95 }}
            onClick={handleRefresh}
            title="Refresh"
          >
            <FiRefreshCw />
          </motion.button>

          <div className="inventory-notification-wrapper">
            <motion.button
              className="inventory-notification-btn"
              whileHover={{ scale: 1.05 }}
              whileTap={{ scale: 0.95 }}
              onClick={() => setShowNotifications(!showNotifications)}
            >
              <FiBell className="inventory-notification-icon" />
              {unreadCount > 0 && (
                <span className="inventory-notification-badge">{unreadCount}</span>
              )}
            </motion.button>

            <AnimatePresence>
              {showNotifications && (
                <motion.div 
                  className="inventory-notification-dropdown"
                  initial={{ opacity: 0, y: -10, scale: 0.95 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -10, scale: 0.95 }}
                  transition={{ duration: 0.2 }}
                >
                  <div className="inventory-notification-header">
                    <h3>Notifications</h3>
                    {unreadCount > 0 && (
                      <button className="inventory-mark-all-read" onClick={markAllAsRead}>
                        Mark all as read
                      </button>
                    )}
                  </div>
                  <div className="inventory-notification-list">
                    {notifications.length > 0 ? (
                      notifications.map(notif => (
                        <div
                          key={notif.id}
                          className={`inventory-notification-item ${notif.read ? 'read' : 'unread'} ${notif.type}`}
                          onClick={() => markAsRead(notif.id)}
                        >
                          <div className="inventory-notification-content">
                            <div className="inventory-notification-icon-wrapper">
                              {notif.type === 'warning' && <FiAlertCircle />}
                              {notif.type === 'info' && <FiInfo />}
                              {notif.type === 'danger' && <FiAlertTriangle />}
                              {notif.type === 'success' && <FiCheckCircle />}
                            </div>
                            <div className="inventory-notification-text">
                              <p>{notif.message}</p>
                              <span className="inventory-notification-time">{notif.time}</span>
                            </div>
                          </div>
                          {!notif.read && <div className="inventory-notification-unread-dot"></div>}
                        </div>
                      ))
                    ) : (
                      <div className="inventory-notification-empty">
                        <FiBell />
                        <p>No notifications</p>
                      </div>
                    )}
                  </div>
                </motion.div>
              )}
            </AnimatePresence>
          </div>

          <div className="inventory-header-buttons">
            {activeModule === 'products' && activeProductSubModule === 'ingredients' && (
              <motion.button
                className="inventory-action-btn inventory-action-btn-primary"
                whileHover={{ scale: 1.05 }}
                whileTap={{ scale: 0.95 }}
                onClick={() => setShowAddProductModal(true)}
              >
                <FiPlus />
                <span>Add Ingredient</span>
              </motion.button>
            )}
            
            {activeModule === 'products' && activeProductSubModule === 'purchase-requests' && (
              <motion.button
                className="inventory-action-btn inventory-action-btn-primary"
                whileHover={{ scale: 1.05 }}
                whileTap={{ scale: 0.95 }}
                onClick={() => setShowPurchaseRequestModal(true)}
              >
                <FiPlus />
                <span>Create Request</span>
              </motion.button>
            )}
            
            {activeModule === 'products' && activeProductSubModule === 'waste' && (
              <motion.button
                className="inventory-action-btn inventory-action-btn-primary"
                whileHover={{ scale: 1.05 }}
                whileTap={{ scale: 0.95 }}
                onClick={() => setShowWasteRecordModal(true)}
              >
                <FiPlus />
                <span>Record Waste</span>
              </motion.button>
            )}
            
            {activeModule === 'equipment' && activeEquipmentSubModule === 'equipment-list' && (
              <motion.button
                className="inventory-action-btn inventory-action-btn-primary"
                whileHover={{ scale: 1.05 }}
                whileTap={{ scale: 0.95 }}
                onClick={() => setShowAddEquipmentModal(true)}
              >
                <FiPlus />
                <span>Add Equipment</span>
              </motion.button>
            )}
            
            {activeModule === 'equipment' && activeEquipmentSubModule === 'equipment-reservations' && (
              <motion.button
                className="inventory-action-btn inventory-action-btn-primary"
                whileHover={{ scale: 1.05 }}
                whileTap={{ scale: 0.95 }}
                onClick={() => setShowEquipmentReservationModal(true)}
              >
                <FiPlus />
                <span>New Reservation</span>
              </motion.button>
            )}
          </div>
        </div>
      </motion.div>

      {/* ============================================================
          STATS CARDS
          ============================================================ */}
      <motion.div 
        className="inventory-stats-grid"
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.1 }}
      >
        {activeModule === 'products' ? (
          <>
            <motion.div className="inventory-stat-card inventory-stat-card-product" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-total">
                <FiPackage />
              </div>
              <div className="inventory-stat-info">
                <h3>Total Items</h3>
                <p className="inventory-stat-value">{stats.products.total}</p>
                <p className="inventory-stat-label">product types</p>
              </div>
            </motion.div>

            <motion.div className="inventory-stat-card" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-warning">
                <FiAlertCircle />
              </div>
              <div className="inventory-stat-info">
                <h3>Low Stock</h3>
                <p className="inventory-stat-value">{stats.products.lowStock}</p>
                <p className="inventory-stat-label">items need reorder</p>
              </div>
            </motion.div>

            <motion.div className="inventory-stat-card" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-danger">
                <FiXCircle />
              </div>
              <div className="inventory-stat-info">
                <h3>Out of Stock</h3>
                <p className="inventory-stat-value">{stats.products.outOfStock}</p>
                <p className="inventory-stat-label">items unavailable</p>
              </div>
            </motion.div>

            <motion.div className="inventory-stat-card" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-expiring">
                <FiClock />
              </div>
              <div className="inventory-stat-info">
                <h3>Expiring Soon</h3>
                <p className="inventory-stat-value">{stats.products.expiring}</p>
                <p className="inventory-stat-label">items within 7 days</p>
              </div>
            </motion.div>
          </>
        ) : (
          <>
            <motion.div className="inventory-stat-card inventory-stat-card-equipment" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-equipment">
                <AiFillProduct />
              </div>
              <div className="inventory-stat-info">
                <h3>Total Equipment</h3>
                <p className="inventory-stat-value">{stats.equipment.total}</p>
                <p className="inventory-stat-label">equipment types</p>
              </div>
            </motion.div>

            <motion.div className="inventory-stat-card" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-inuse">
                <FiClock />
              </div>
              <div className="inventory-stat-info">
                <h3>In Use</h3>
                <p className="inventory-stat-value">{stats.equipment.inUse}</p>
                <p className="inventory-stat-label">pieces currently used</p>
              </div>
            </motion.div>

            <motion.div className="inventory-stat-card" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-available">
                <FiCheckCircle />
              </div>
              <div className="inventory-stat-info">
                <h3>Available</h3>
                <p className="inventory-stat-value">{stats.equipment.available}</p>
                <p className="inventory-stat-label">pieces ready to use</p>
              </div>
            </motion.div>

            <motion.div className="inventory-stat-card" whileHover={{ scale: 1.02, y: -5 }}>
              <div className="inventory-stat-icon inventory-stat-icon-danger">
                <FiAlertCircle />
              </div>
              <div className="inventory-stat-info">
                <h3>Damaged/Missing</h3>
                <p className="inventory-stat-value">{(stats.equipment.damaged || 0) + (stats.equipment.missing || 0)}</p>
                <p className="inventory-stat-label">needs attention</p>
              </div>
            </motion.div>
          </>
        )}
      </motion.div>

      {/* ============================================================
          MODULE NAVIGATION
          ============================================================ */}
      <div className="inventory-module-nav">
        <motion.button
          className={`inventory-module-btn inventory-module-btn-product ${activeModule === 'products' ? 'active' : ''}`}
          onClick={() => {
            setActiveModule('products');
            setCurrentPage(1);
            setSearchTerm('');
            setSelectedCategory('all');
          }}
          whileHover={{ y: -2 }}
          whileTap={{ scale: 0.95 }}
        >
          <FiPackage />
          <span>Products</span>
          <span className="inventory-module-badge">{stats.products.total}</span>
        </motion.button>
        
        <motion.button
          className={`inventory-module-btn inventory-module-btn-equipment ${activeModule === 'equipment' ? 'active' : ''}`}
          onClick={() => {
            setActiveModule('equipment');
            setCurrentPage(1);
            setSearchTerm('');
            setSelectedCategory('all');
          }}
          whileHover={{ y: -2 }}
          whileTap={{ scale: 0.95 }}
        >
          <AiFillProduct />
          <span>Equipment</span>
          <span className="inventory-module-badge">{stats.equipment.total}</span>
        </motion.button>
      </div>

      {/* ============================================================
          SUB-NAVIGATION
          ============================================================ */}
      <div className="inventory-sub-nav-wrapper">
        <div className="inventory-sub-nav">
          {activeModule === 'products' ? (
            productSubNavItems.map(item => (
              <motion.button
                key={item.id}
                className={`inventory-sub-nav-btn ${activeProductSubModule === item.id ? 'active' : ''}`}
                onClick={() => {
                  setActiveProductSubModule(item.id);
                  setCurrentPage(1);
                }}
                whileHover={{ y: -2 }}
                whileTap={{ scale: 0.95 }}
              >
                {item.icon}
                <span>{item.name}</span>
              </motion.button>
            ))
          ) : (
            equipmentSubNavItems.map(item => (
              <motion.button
                key={item.id}
                className={`inventory-sub-nav-btn ${activeEquipmentSubModule === item.id ? 'active' : ''}`}
                onClick={() => {
                  setActiveEquipmentSubModule(item.id);
                  setCurrentPage(1);
                }}
                whileHover={{ y: -2 }}
                whileTap={{ scale: 0.95 }}
              >
                {item.icon}
                <span>{item.name}</span>
              </motion.button>
            ))
          )}
        </div>
      </div>

      {/* ============================================================
          SEARCH AND FILTER BAR
          ============================================================ */}
      {(activeModule === 'products' && activeProductSubModule === 'ingredients') ||
       (activeModule === 'equipment' && activeEquipmentSubModule === 'equipment-list') ? (
        <motion.div 
          className="inventory-search-filter-bar"
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.2 }}
        >
          <div className="inventory-search-wrapper">
            <FiSearch className="inventory-search-icon" />
            <input
              type="text"
              placeholder={
                activeModule === 'products' ? "Search by ID, name, SKU, or category..." :
                "Search by ID, name, category, or sub-category..."
              }
              value={searchTerm || ''}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="inventory-search-input"
            />
            {searchTerm && (
              <motion.button
                className="inventory-clear-search"
                initial={{ scale: 0 }}
                animate={{ scale: 1 }}
                onClick={() => setSearchTerm('')}
              >
                <FiXCircle />
              </motion.button>
            )}
          </div>

          <div className="inventory-filter-actions">
            <motion.button
              className={`inventory-filter-btn ${showFilters ? 'active' : ''}`}
              onClick={() => setShowFilters(!showFilters)}
              whileHover={{ scale: 1.05 }}
              whileTap={{ scale: 0.95 }}
            >
              <FiFilter />
              <span>Filters</span>
              {(selectedCategory !== 'all') && (
                <motion.span 
                  className="inventory-filter-badge"
                  initial={{ scale: 0 }}
                  animate={{ scale: 1 }}
                >
                  1
                </motion.span>
              )}
            </motion.button>

            <div className="inventory-view-toggle">
              <motion.button
                className={`inventory-view-btn ${viewMode === 'table' ? 'active' : ''}`}
                onClick={() => setViewMode('table')}
                whileHover={{ scale: 1.1 }}
                whileTap={{ scale: 0.9 }}
              >
                <FiList />
              </motion.button>
              <motion.button
                className={`inventory-view-btn ${viewMode === 'grid' ? 'active' : ''}`}
                onClick={() => setViewMode('grid')}
                whileHover={{ scale: 1.1 }}
                whileTap={{ scale: 0.9 }}
              >
                <FiGrid />
              </motion.button>
            </div>
          </div>
        </motion.div>
      ) : null}

      {/* ============================================================
          CATEGORY FILTER PANEL
          ============================================================ */}
      <AnimatePresence>
        {showFilters && (
          <motion.div 
            className="inventory-filters-panel"
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
          >
            <div className="inventory-filters-header">
              <h3>Filter by Category</h3>
              <motion.button
                className="inventory-close-filters"
                onClick={() => setShowFilters(false)}
                whileHover={{ scale: 1.1 }}
                whileTap={{ scale: 0.9 }}
              >
                <FiXCircle />
              </motion.button>
            </div>

            <div className="inventory-filters-content">
              {activeModule === 'products' ? (
                <div className="inventory-category-grid">
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'all' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('all')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">📦</span>
                    <span className="inventory-category-name">All Products</span>
                    <span className="inventory-category-count">{products.length}</span>
                  </motion.button>
                  
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'direct' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('direct')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">🥩</span>
                    <span className="inventory-category-name">Direct</span>
                    <span className="inventory-category-count">{products.filter(p => p.ingredient_type === 'direct').length}</span>
                  </motion.button>
                  
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'reusable' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('reusable')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">🫒</span>
                    <span className="inventory-category-name">Reusable</span>
                    <span className="inventory-category-count">{products.filter(p => p.ingredient_type === 'reusable').length}</span>
                  </motion.button>
                  
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'estimated' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('estimated')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">🧂</span>
                    <span className="inventory-category-name">Estimated</span>
                    <span className="inventory-category-count">{products.filter(p => p.ingredient_type === 'estimated').length}</span>
                  </motion.button>
                  
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'low-stock' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('low-stock')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">⚠️</span>
                    <span className="inventory-category-name">Low Stock</span>
                    <span className="inventory-category-count">{products.filter(p => p.status === 'low-stock').length}</span>
                  </motion.button>
                  
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'out-of-stock' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('out-of-stock')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">❌</span>
                    <span className="inventory-category-name">Out of Stock</span>
                    <span className="inventory-category-count">{products.filter(p => p.status === 'out-of-stock').length}</span>
                  </motion.button>
                  
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'expiring' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('expiring')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">📅</span>
                    <span className="inventory-category-name">Expiring Soon</span>
                    <span className="inventory-category-count">{products.filter(p => isExpiringSoon(p.expiry_date)).length}</span>
                  </motion.button>
                </div>
              ) : (
                <div className="inventory-category-grid">
                  <motion.button
                    className={`inventory-category-chip ${selectedCategory === 'all' ? 'active' : ''}`}
                    onClick={() => setSelectedCategory('all')}
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                  >
                    <span className="inventory-category-icon">🔧</span>
                    <span className="inventory-category-name">All Equipment</span>
                    <span className="inventory-category-count">{equipment.length}</span>
                  </motion.button>
                  
                  {equipmentMainCategories.map(cat => (
                    <motion.button
                      key={cat}
                      className={`inventory-category-chip ${selectedCategory === cat ? 'active' : ''}`}
                      onClick={() => setSelectedCategory(cat)}
                      whileHover={{ scale: 1.05 }}
                      whileTap={{ scale: 0.95 }}
                    >
                      <span className="inventory-category-icon">
                        {cat === 'Eating Utensils' && '🍴'}
                        {cat === 'Tableware' && '🍽️'}
                        {cat === 'Serving Equipment' && '🥄'}
                        {cat === 'Cooking Equipment' && '🍳'}
                        {cat === 'Food Storage' && '📦'}
                        {cat === 'Furniture' && '🪑'}
                        {cat === 'Cleaning & Hygiene' && '🧹'}
                        {cat === 'Extra Essentials' && '🔌'}
                      </span>
                      <span className="inventory-category-name">{cat}</span>
                      <span className="inventory-category-count">
                        {equipment.filter(e => e?.category === cat).length}
                      </span>
                    </motion.button>
                  ))}
                </div>
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ============================================================
          CONTENT - PRODUCTS INGREDIENTS
          ============================================================ */}
      {activeModule === 'products' && activeProductSubModule === 'ingredients' && (
        <div className="inventory-table-container">
          {viewMode === 'table' ? (
            <div className="inventory-list">
              <table className="inventory-table">
                <thead>
                  <tr>
                    <th style={{ width: '30px' }}>
                      <input 
                        type="checkbox"
                        checked={selectedItems.length === paginatedItems.length && paginatedItems.length > 0}
                        onChange={() => {
                          if (selectedItems.length === paginatedItems.length) {
                            setSelectedItems([]);
                          } else {
                            setSelectedItems(paginatedItems.map(item => item?.product_id).filter(Boolean));
                          }
                        }}
                      />
                    </th>
                    <th>ID</th>
                    <th>Product Name</th>
                    <th>Type</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Min / Max</th>
                    <th>Status</th>
                    <th>Expiry</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {paginatedItems.map((item, index) => {
                    if (!item) return null;
                    const status = getStatusDetails(item.status);
                    const StatusIcon = status.icon;
                    const expiringSoon = isExpiringSoon(item.expiry_date);
                    const ingredientTypeInfo = ingredientTypes[item.ingredient_type] || ingredientTypes.direct;
                    
                    return (
                      <motion.tr
                        key={item.product_id || index}
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: index * 0.05 }}
                        whileHover={{ backgroundColor: 'var(--hover-bg)' }}
                        className={selectedItems.includes(item.product_id) ? 'inventory-selected' : ''}
                      >
                        <td onClick={(e) => e.stopPropagation()}>
                          <input 
                            type="checkbox"
                            checked={selectedItems.includes(item.product_id)}
                            onChange={() => {
                              if (selectedItems.includes(item.product_id)) {
                                setSelectedItems(selectedItems.filter(id => id !== item.product_id));
                              } else {
                                setSelectedItems([...selectedItems, item.product_id]);
                              }
                            }}
                          />
                        </td>
                        <td><span className="inventory-item-id">{item.product_id}</span></td>
                        <td>
                          <div className="inventory-list-item-name">
                            <span>{item.name}</span>
                            {!item.active && <span className="inventory-status-badge inventory-status-badge-inactive">Archived</span>}
                          </div>
                        </td>
                        <td>
                          <div className="inventory-ingredient-type-badge" style={{ backgroundColor: ingredientTypeInfo.bgColor, color: ingredientTypeInfo.color }}>
                            {ingredientTypeInfo.icon}
                            <span>{ingredientTypeInfo.name}</span>
                          </div>
                        </td>
                        <td>{item.category || '-'}</td>
                        <td>
                          <div className="inventory-quantity-badge">
                            <span className="inventory-quantity-value">{item.quantity || 0}</span>
                            <span className="inventory-quantity-unit">{item.unit}</span>
                          </div>
                          <div className="inventory-progress-bar">
                            <motion.div 
                              className={`inventory-progress-fill ${item.status}`}
                              initial={{ width: 0 }}
                              animate={{ width: `${Math.min(((item.quantity || 0) / (item.max_stock || 100)) * 100, 100)}%` }}
                              transition={{ duration: 1 }}
                            />
                          </div>
                        </td>
                        <td>
                          <span className="inventory-min-max">
                            {item.min_stock || 10} / {item.max_stock || 100}
                          </span>
                        </td>
                        <td>
                          <div className={`inventory-status-badge ${item.active ? item.status : 'inactive'}`}>
                            <StatusIcon />
                            <span>{item.active ? status.text : 'Archived'}</span>
                          </div>
                        </td>
                        <td>
                          {item.expiry_date ? (
                            <span style={{ color: expiringSoon ? '#ef4444' : 'inherit' }}>
                              {new Date(item.expiry_date).toLocaleDateString()}
                              {expiringSoon && <FiAlertCircle style={{ marginLeft: 4, color: '#ef4444' }} />}
                            </span>
                          ) : '-'}
                        </td>
                        <td onClick={(e) => e.stopPropagation()}>
                          <div className="inventory-action-buttons">
                            <motion.button
                              className="inventory-action-icon-btn"
                              whileHover={{ scale: 1.1 }}
                              whileTap={{ scale: 0.9 }}
                              onClick={() => handleViewDetails(item)}
                              data-tooltip="View Details"
                            >
                              <FiEye />
                            </motion.button>
                            <motion.button
                              className="inventory-action-icon-btn"
                              whileHover={{ scale: 1.1 }}
                              whileTap={{ scale: 0.9 }}
                              onClick={() => handleEdit(item)}
                              data-tooltip="Edit"
                            >
                              <FiEdit2 />
                            </motion.button>
                            <motion.button
                              className="inventory-action-icon-btn"
                              whileHover={{ scale: 1.1 }}
                              whileTap={{ scale: 0.9 }}
                              onClick={() => {
                                setSelectedItem(item);
                                setShowStockMovementModal(true);
                              }}
                              data-tooltip="Record Movement"
                            >
                              <BsCartPlus />
                            </motion.button>
                            {item.active ? (
                              <motion.button
                                className="inventory-action-icon-btn inventory-action-icon-btn-archive"
                                whileHover={{ scale: 1.1 }}
                                whileTap={{ scale: 0.9 }}
                                onClick={() => handleArchive(item.product_id)}
                                data-tooltip="Archive"
                              >
                                <FiArchive />
                              </motion.button>
                            ) : (
                              <motion.button
                                className="inventory-action-icon-btn inventory-action-icon-btn-restore"
                                whileHover={{ scale: 1.1 }}
                                whileTap={{ scale: 0.9 }}
                                onClick={() => handleRestore(item.product_id)}
                                data-tooltip="Restore"
                              >
                                <FiRefreshCw />
                              </motion.button>
                            )}
                          </div>
                        </td>
                      </motion.tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="inventory-product-grid">
              {paginatedItems.map((item, index) => {
                const status = getStatusDetails(item.status);
                const StatusIcon = status.icon;
                const ingredientTypeInfo = ingredientTypes[item.ingredient_type] || ingredientTypes.direct;
                return (
                  <motion.div
                    key={item.product_id}
                    className="inventory-product-card"
                    initial={{ opacity: 0, scale: 0.9 }}
                    animate={{ opacity: 1, scale: 1 }}
                    transition={{ delay: index * 0.05 }}
                    whileHover={{ y: -4 }}
                  >
                    <div className="inventory-product-card-header">
                      <div className={`inventory-product-card-type`} style={{ backgroundColor: ingredientTypeInfo.bgColor, color: ingredientTypeInfo.color }}>
                        {ingredientTypeInfo.icon}
                        <span>{ingredientTypeInfo.name}</span>
                      </div>
                      <div className={`inventory-status-badge ${item.status}`}>
                        <StatusIcon />
                        <span>{status.text}</span>
                      </div>
                    </div>
                    <div className="inventory-product-card-name">{item.name}</div>
                    <div className="inventory-product-card-id">{item.product_id}</div>
                    <div className="inventory-product-card-stats">
                      <div className="inventory-product-card-stat">
                        <span className="label">Stock</span>
                        <span className="value">{item.quantity || 0} {item.unit}</span>
                      </div>
                      <div className="inventory-product-card-stat">
                        <span className="label">Category</span>
                        <span className="value">{item.category || '-'}</span>
                      </div>
                    </div>
                    <div className="inventory-product-card-actions">
                      <motion.button onClick={() => handleViewDetails(item)} whileHover={{ scale: 1.1 }}><FiEye /></motion.button>
                      <motion.button onClick={() => handleEdit(item)} whileHover={{ scale: 1.1 }}><FiEdit2 /></motion.button>
                      <motion.button onClick={() => {
                        setSelectedItem(item);
                        setShowStockMovementModal(true);
                      }} whileHover={{ scale: 1.1 }}><BsCartPlus /></motion.button>
                    </div>
                  </motion.div>
                );
              })}
            </div>
          )}

          {paginatedItems.length === 0 && (
            <motion.div className="inventory-empty-state" initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }}>
              <FiPackage className="inventory-empty-icon" />
              <h3>No ingredients found</h3>
              <p>Try adjusting your search or filter criteria</p>
              <motion.button className="inventory-clear-filters-btn" whileHover={{ scale: 1.05 }} whileTap={{ scale: 0.95 }} onClick={() => {
                setSearchTerm('');
                setSelectedCategory('all');
                setCurrentPage(1);
              }}>Clear Filters</motion.button>
            </motion.div>
          )}
          
          {currentItems.length > 0 && (
            <motion.div className="inventory-pagination" initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.4 }}>
              <div className="inventory-pagination-info">
                Showing {(currentPage - 1) * ITEMS_PER_PAGE + 1} to {Math.min(currentPage * ITEMS_PER_PAGE, currentItems.length)} of {currentItems.length} items
              </div>
              <div className="inventory-pagination-controls">
                <motion.button className="inventory-pagination-btn" onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))} disabled={currentPage === 1} whileHover={{ scale: 1.1 }} whileTap={{ scale: 0.9 }}>
                  <FiChevronLeft />
                </motion.button>
                {[...Array(Math.min(totalPages, 5))].map((_, i) => {
                  let pageNum;
                  if (totalPages <= 5) {
                    pageNum = i + 1;
                  } else if (currentPage <= 3) {
                    pageNum = i + 1;
                  } else if (currentPage >= totalPages - 2) {
                    pageNum = totalPages - 4 + i;
                  } else {
                    pageNum = currentPage - 2 + i;
                  }
                  return (
                    <motion.button key={i} className={`inventory-pagination-btn ${currentPage === pageNum ? 'active' : ''}`} onClick={() => setCurrentPage(pageNum)} whileHover={{ scale: 1.1 }} whileTap={{ scale: 0.9 }}>
                      {pageNum}
                    </motion.button>
                  );
                })}
                <motion.button className="inventory-pagination-btn" onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))} disabled={currentPage === totalPages} whileHover={{ scale: 1.1 }} whileTap={{ scale: 0.9 }}>
                  <FiChevronRight />
                </motion.button>
              </div>
            </motion.div>
          )}
        </div>
      )}

      {/* ============================================================
          CONTENT - STOCK LEVELS
          ============================================================ */}
      {activeModule === 'products' && activeProductSubModule === 'stock-levels' && (
        <div className="inventory-stock-levels">
          <div className="inventory-stock-summary">
            <div className="inventory-stock-summary-card">
              <div className="inventory-stock-summary-icon">📊</div>
              <div className="inventory-stock-summary-info">
                <h4>Total Value</h4>
                <p>₱{(products.reduce((sum, p) => sum + (p.quantity || 0) * (p.cost_per_unit || 0), 0)).toLocaleString()}</p>
              </div>
            </div>
            <div className="inventory-stock-summary-card">
              <div className="inventory-stock-summary-icon">📦</div>
              <div className="inventory-stock-summary-info">
                <h4>Total Units</h4>
                <p>{products.reduce((sum, p) => sum + (p.quantity || 0), 0).toLocaleString()}</p>
              </div>
            </div>
            <div className="inventory-stock-summary-card">
              <div className="inventory-stock-summary-icon">⚠️</div>
              <div className="inventory-stock-summary-info">
                <h4>Below Reorder Point</h4>
                <p>{products.filter(p => (p.quantity || 0) <= (p.reorder_point || 15)).length}</p>
              </div>
            </div>
          </div>
          
          <div className="inventory-stock-table-container">
            <table className="inventory-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Current Stock</th>
                  <th>Min Stock</th>
                  <th>Max Stock</th>
                  <th>Reorder Point</th>
                  <th>Status</th>
                  <th>Days Until Out</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {products.map(product => {
                  const avgDailyUsage = product.avg_daily_usage || 5;
                  const daysUntilOut = Math.ceil((product.quantity || 0) / avgDailyUsage);
                  const isEditing = editingStock === product.product_id;
                  
                  return (
                    <tr key={product.product_id}>
                      <td>{product.name}</td>
                      <td className={product.quantity <= (product.min_stock || 10) ? 'stock-critical' : ''}>
                        {isEditing ? (
                          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                            <input
                              type="number"
                              value={editingStockValue}
                              onChange={(e) => setEditingStockValue(e.target.value)}
                              style={{
                                width: '80px',
                                padding: '4px 8px',
                                border: '1px solid #3b82f6',
                                borderRadius: '4px',
                                fontSize: '14px'
                              }}
                              autoFocus
                            />
                            <button
                              onClick={() => handleQuickStockUpdate(product.product_id, editingStockValue)}
                              style={{
                                padding: '4px 12px',
                                background: '#3b82f6',
                                color: '#fff',
                                border: 'none',
                                borderRadius: '4px',
                                cursor: 'pointer',
                                fontSize: '12px'
                              }}
                            >
                              Save
                            </button>
                            <button
                              onClick={() => {
                                setEditingStock(null);
                                setEditingStockValue('');
                              }}
                              style={{
                                padding: '4px 8px',
                                background: '#6b7280',
                                color: '#fff',
                                border: 'none',
                                borderRadius: '4px',
                                cursor: 'pointer',
                                fontSize: '12px'
                              }}
                            >
                              Cancel
                            </button>
                          </div>
                        ) : (
                          <>
                            {product.quantity || 0} {product.unit}
                            <button
                              onClick={() => {
                                setEditingStock(product.product_id);
                                setEditingStockValue(String(product.quantity || 0));
                              }}
                              style={{
                                marginLeft: '8px',
                                padding: '2px 8px',
                                background: 'transparent',
                                border: '1px solid #d1d5db',
                                borderRadius: '4px',
                                cursor: 'pointer',
                                fontSize: '11px',
                                color: '#6b7280'
                              }}
                            >
                              ✎
                            </button>
                          </>
                        )}
                      </td>
                      <td>{product.min_stock || 10}</td>
                      <td>{product.max_stock || 100}</td>
                      <td>{product.reorder_point || 15}</td>
                      <td>
                        <div className={`inventory-stock-status ${product.status}`}>
                          {product.status === 'in-stock' && <FiCheckCircle />}
                          {product.status === 'low-stock' && <FiAlertCircle />}
                          {product.status === 'out-of-stock' && <FiXCircle />}
                          <span>{product.status === 'in-stock' ? 'In Stock' : product.status === 'low-stock' ? 'Low Stock' : 'Out of Stock'}</span>
                        </div>
                      </td>
                      <td className={daysUntilOut <= 3 ? 'urgent' : ''}>{daysUntilOut > 0 ? `${daysUntilOut} days` : 'Out of stock'}</td>
                      <td>
                        <button
                          onClick={() => {
                            setSelectedItem(product);
                            setStockMovement({
                              type: 'purchase',
                              quantity: '',
                              reason: '',
                              reference: '',
                              date: new Date().toISOString().split('T')[0]
                            });
                            setShowStockMovementModal(true);
                          }}
                          style={{
                            padding: '4px 12px',
                            background: '#10b981',
                            color: '#fff',
                            border: 'none',
                            borderRadius: '4px',
                            cursor: 'pointer',
                            fontSize: '12px'
                          }}
                        >
                          Adjust
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ============================================================
          CONTENT - MOVEMENTS
          ============================================================ */}
      {activeModule === 'products' && activeProductSubModule === 'movements' && (
        <div className="inventory-movements">
          <div className="inventory-movements-filters">
            <select className="inventory-filter-select">
              <option value="all">All Movements</option>
              <option value="purchase">Purchase</option>
              <option value="usage">Usage</option>
              <option value="return">Return</option>
              <option value="waste">Waste</option>
              <option value="adjustment">Adjustment</option>
            </select>
            <input type="date" className="inventory-filter-date" />
          </div>
          <div className="inventory-movements-table-container">
            <table className="inventory-table">
              <thead>
                <tr>
                  <th>Transaction ID</th>
                  <th>Ingredient</th>
                  <th>Movement Type</th>
                  <th>Quantity</th>
                  <th>Date</th>
                  <th>Updated By</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {inventoryMovements.map(movement => (
                  <tr key={movement.id}>
                    <td><span className="inventory-item-id">{movement.id}</span></td>
                    <td>{movement.ingredient}</td>
                    <td>
                      <div className={`inventory-movement-type ${movement.type}`}>
                        {movement.type === 'purchase' && <BsCartPlus />}
                        {movement.type === 'usage' && <BsCartDash />}
                        {movement.type === 'return' && <BsArrowReturnLeft />}
                        {movement.type === 'waste' && <FiTrash2 />}
                        <span>{movement.type.charAt(0).toUpperCase() + movement.type.slice(1)}</span>
                      </div>
                    </td>
                    <td>{movement.quantity} {movement.unit}</td>
                    <td>{new Date(movement.date).toLocaleString()}</td>
                    <td>{movement.updatedBy}</td>
                    <td>{movement.reason || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ============================================================
          CONTENT - COMPUTATION
          ============================================================ */}
      {activeModule === 'products' && activeProductSubModule === 'computation' && (
        <div className="inventory-computation">
          <div className="inventory-computation-header">
            <h3><BsCalculator /> Automatic Ingredient Computation</h3>
            <p>Calculate ingredient requirements based on number of guests</p>
          </div>
          
          <div className="inventory-computation-form">
            <div className="inventory-form-row">
              <div className="inventory-form-group">
                <label>Select Recipe / Event Type</label>
                <select className="inventory-form-control">
                  <option>Wedding Package A</option>
                  <option>Birthday Package B</option>
                  <option>Corporate Event</option>
                  <option>Buffet Setup</option>
                </select>
              </div>
              <div className="inventory-form-group">
                <label>Number of Guests</label>
                <input type="number" placeholder="Enter guest count" className="inventory-form-control" />
              </div>
            </div>
            
            <div className="inventory-form-group">
              <label>Buffer Allowance (%)</label>
              <input type="number" defaultValue="5" className="inventory-form-control" />
              <small>Recommended: 5-10% for unexpected demand</small>
            </div>
            
            <motion.button className="inventory-computation-btn" whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}>
              <BsCalculator /> Calculate Requirements
            </motion.button>
          </div>
          
          <div className="inventory-computation-results">
            <h4>Calculated Ingredient Requirements</h4>
            <table className="inventory-table">
              <thead>
                <tr>
                  <th>Ingredient</th>
                  <th>Type</th>
                  <th>Per Pax</th>
                  <th>Base Required</th>
                  <th>Yield Factor</th>
                  <th>Buffer</th>
                  <th>Total Required</th>
                  <th>Current Stock</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Chicken</td>
                  <td><span className="ingredient-type-direct">Direct</span></td>
                  <td>0.25 kg</td>
                  <td>25 kg</td>
                  <td>80%</td>
                  <td>1.25 kg</td>
                  <td>31.25 kg</td>
                  <td>45 kg</td>
                  <td className="status-ok">Sufficient</td>
                </tr>
                <tr>
                  <td>Cooking Oil</td>
                  <td><span className="ingredient-type-reusable">Reusable</span></td>
                  <td>0.05 L</td>
                  <td>5 L</td>
                  <td>3x reuse</td>
                  <td>0.25 L</td>
                  <td>1.92 L</td>
                  <td>2 L</td>
                  <td className="status-ok">Sufficient</td>
                </tr>
                <tr>
                  <td>Salt</td>
                  <td><span className="ingredient-type-estimated">Estimated</span></td>
                  <td>2 g</td>
                  <td>200 g</td>
                  <td>100%</td>
                  <td>10 g</td>
                  <td>210 g</td>
                  <td>150 g</td>
                  <td className="status-warning">Low</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ============================================================
          CONTENT - PURCHASE REQUESTS
          ============================================================ */}
      {activeModule === 'products' && activeProductSubModule === 'purchase-requests' && (
        <div className="inventory-purchase-requests">
          <div className="inventory-auto-suggestions">
            <h4><FiZap /> Auto Purchase Suggestions</h4>
            <div className="inventory-suggestions-list">
              {getAutoPurchaseSuggestions().map(suggestion => (
                <div key={suggestion.productId} className="inventory-suggestion-item">
                  <div className="inventory-suggestion-info">
                    <span className="inventory-suggestion-name">{suggestion.name}</span>
                    <span className="inventory-suggestion-stock">Current: {suggestion.currentStock}</span>
                    <span className="inventory-suggestion-reorder">Reorder at: {suggestion.reorderPoint}</span>
                  </div>
                  <div className="inventory-suggestion-action">
                    <span>Suggested: {suggestion.suggestedQuantity} units</span>
                    <button className="inventory-suggestion-btn">Create Request</button>
                  </div>
                </div>
              ))}
            </div>
          </div>
          
          <div className="inventory-purchase-requests-table">
            <h4>Pending Purchase Requests</h4>
            <table className="inventory-table">
              <thead>
                <tr>
                  <th>Request ID</th>
                  <th>Ingredient</th>
                  <th>Quantity</th>
                  <th>Supplier</th>
                  <th>Urgency</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {purchaseRequests.map(request => (
                  <tr key={request.id}>
                    <td><span className="inventory-item-id">{request.id}</span></td>
                    <td>{request.ingredient}</td>
                    <td>{request.quantity}</td>
                    <td>{request.supplier}</td>
                    <td>
                      <div className={`inventory-urgency ${request.urgency}`}>
                        {request.urgency === 'critical' && <FiAlertCircle />}
                        {request.urgency === 'normal' && <FiClock />}
                        <span>{request.urgency}</span>
                      </div>
                    </td>
                    <td>
                      <div className={`inventory-request-status ${request.status}`}>
                        {request.status === 'pending' && <FiClock />}
                        {request.status === 'approved' && <FiCheckCircle />}
                        {request.status === 'ordered' && <FiTruck />}
                        <span>{request.status}</span>
                      </div>
                    </td>
                    <td>{new Date(request.dateRequested).toLocaleDateString()}</td>
                    <td>
                      <div className="inventory-action-buttons">
                        <button className="inventory-action-icon-btn"><FiEye /></button>
                        <button className="inventory-action-icon-btn"><FiEdit2 /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ============================================================
          CONTENT - SUPPLIERS
          ============================================================ */}
      {activeModule === 'products' && activeProductSubModule === 'suppliers' && (
        <div className="inventory-suppliers">
          <div className="inventory-suppliers-grid">
            {suppliers.map(supplier => (
              <div key={supplier.id || supplier.supplier_id} className="inventory-supplier-card">
                <div className="inventory-supplier-header">
                  <div className="inventory-supplier-icon"><FiTruck /></div>
                  <div className="inventory-supplier-info">
                    <h4>{supplier.name}</h4>
                    <span className="inventory-supplier-id">{supplier.id || supplier.supplier_id}</span>
                  </div>
                  <div className="inventory-supplier-status">{supplier.status || 'active'}</div>
                </div>
                <div className="inventory-supplier-details">
                  <div className="inventory-supplier-detail"><BsPersonBadge /> {supplier.contact || supplier.contact_person || 'N/A'}</div>
                  <div className="inventory-supplier-detail"><BsTelephone /> {supplier.phone || supplier.phone_number || 'N/A'}</div>
                  <div className="inventory-supplier-detail"><FiPackage /> {supplier.products || supplier.product_categories || 'N/A'}</div>
                  <div className="inventory-supplier-detail"><FiStar /> Rating: {supplier.rating || 'N/A'}/5</div>
                </div>
                <div className="inventory-supplier-actions">
                  <button className="inventory-supplier-btn">View Products</button>
                  <button className="inventory-supplier-btn">Place Order</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ============================================================
          CONTENT - WASTE
          ============================================================ */}
      {activeModule === 'products' && activeProductSubModule === 'waste' && (
        <div className="inventory-waste">
          <div className="inventory-waste-summary">
            <div className="inventory-waste-stat">
              <span className="inventory-waste-stat-value">₱{(wasteRecords.reduce((sum, w) => sum + (w.quantity * 100), 0)).toLocaleString()}</span>
              <span className="inventory-waste-stat-label">Total Waste Cost</span>
            </div>
            <div className="inventory-waste-stat">
              <span className="inventory-waste-stat-value">{wasteRecords.length}</span>
              <span className="inventory-waste-stat-label">Waste Records</span>
            </div>
          </div>
          
          <div className="inventory-waste-table-container">
            <table className="inventory-table">
              <thead>
                <tr>
                  <th>Ingredient</th>
                  <th>Quantity Wasted</th>
                  <th>Reason</th>
                  <th>Date</th>
                  <th>Recorded By</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {wasteRecords.map(waste => (
                  <tr key={waste.id || waste.waste_record_id}>
                    <td>{waste.ingredient}</td>
                    <td className="waste-quantity">{waste.quantity}</td>
                    <td>
                      <div className={`inventory-waste-reason ${waste.reason}`}>
                        {waste.reason === 'spoilage' && <FiThermometer />}
                        {waste.reason === 'damage' && <FiAlertTriangle />}
                        {waste.reason === 'expired' && <FiCalendar />}
                        <span>{waste.reason}</span>
                      </div>
                    </td>
                    <td>{new Date(waste.date || waste.created_at).toLocaleDateString()}</td>
                    <td>{waste.recordedBy || waste.recorder?.name || 'System'}</td>
                    <td><button className="inventory-action-icon-btn"><FiEye /></button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ============================================================
          CONTENT - EQUIPMENT LIST - FIXED
          ============================================================ */}
      {activeModule === 'equipment' && activeEquipmentSubModule === 'equipment-list' && (
        <div className="inventory-table-container">
          {viewMode === 'table' ? (
            <div className="inventory-list">
              <table className="inventory-table">
                <thead>
                  <tr>
                    <th>Equipment ID</th>
                    <th>Equipment Name</th>
                    <th>Category</th>
                    <th>Sub Category</th>
                    <th>Total</th>
                    <th>In Use</th>
                    <th>Available</th>
                    <th>Reserved</th>
                    <th>Damaged</th>
                    <th>Missing</th>
                    <th>Condition</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {paginatedItems.length > 0 ? (
                    paginatedItems.map((item, index) => {
                      const equipmentId = item.equipment_id || item.id || `eq-${index}`;
                      // ✅ FIX: Ensure all values are primitives (strings/numbers), not objects
                      const category = typeof item.category === 'string' ? item.category : (item.category?.name || item.category || '-');
                      const subCategory = typeof item.sub_category === 'string' ? item.sub_category : (item.sub_category?.name || item.sub_category || '-');
                      const condition = typeof item.condition === 'string' ? item.condition : (item.condition?.name || item.condition || 'Good');
                      
                      return (
                        <motion.tr
                          key={equipmentId}
                          initial={{ opacity: 0, x: -20 }}
                          animate={{ opacity: 1, x: 0 }}
                          transition={{ delay: index * 0.05 }}
                        >
                          <td><span className="inventory-item-id">{equipmentId}</span></td>
                          <td>{item.name || 'Unnamed Equipment'}</td>
                          <td>{category}</td>
                          <td>{subCategory}</td>
                          <td>{item.total_quantity || 0}</td>
                          <td style={{ color: '#f59e0b' }}>{item.in_use || 0}</td>
                          <td style={{ color: '#10b981' }}>{item.available || 0}</td>
                          <td style={{ color: '#3b82f6' }}>{item.reserved || 0}</td>
                          <td style={{ color: '#ef4444' }}>{item.damaged || 0}</td>
                          <td style={{ color: '#f59e0b' }}>{item.missing || 0}</td>
                          <td>
                            <div className={`inventory-condition-badge ${condition.toLowerCase()}`}>
                              {condition}
                            </div>
                          </td>
                          <td>
                            <div className="inventory-action-buttons">
                              <button className="inventory-action-icon-btn" onClick={() => handleViewDetails(item)}><FiEye /></button>
                              <button className="inventory-action-icon-btn" onClick={() => handleEdit(item)}><FiEdit2 /></button>
                              <button className="inventory-action-icon-btn"><FiTool /></button>
                              {item.active !== false ? (
                                <button className="inventory-action-icon-btn inventory-action-icon-btn-archive" onClick={() => handleArchive(equipmentId)}><FiArchive /></button>
                              ) : (
                                <button className="inventory-action-icon-btn inventory-action-icon-btn-restore" onClick={() => handleRestore(equipmentId)}><FiRefreshCw /></button>
                              )}
                            </div>
                          </td>
                        </motion.tr>
                      );
                    })
                  ) : (
                    <tr>
                      <td colSpan="12" style={{ textAlign: 'center', padding: '40px 0', color: '#6b7280' }}>
                        <AiFillProduct style={{ fontSize: '48px', marginBottom: '12px', opacity: 0.3 }} />
                        <p>No equipment found</p>
                        <p style={{ fontSize: '13px', marginTop: '4px' }}>Try adjusting your search or filter criteria</p>
                        <button 
                          onClick={() => {
                            setSearchTerm('');
                            setSelectedCategory('all');
                            setShowActive(true);
                            setShowInactive(false);
                            handleRefresh();
                          }}
                          style={{
                            marginTop: '12px',
                            padding: '8px 20px',
                            background: '#3b82f6',
                            color: '#fff',
                            border: 'none',
                            borderRadius: '6px',
                            cursor: 'pointer',
                            fontSize: '14px'
                          }}
                        >
                          Reset Filters & Refresh
                        </button>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="inventory-product-grid">
              {paginatedItems.map((item, index) => (
                <motion.div
                  key={item.equipment_id || index}
                  className="inventory-product-card"
                  initial={{ opacity: 0, scale: 0.9 }}
                  animate={{ opacity: 1, scale: 1 }}
                  transition={{ delay: index * 0.05 }}
                  whileHover={{ y: -4 }}
                >
                  <div className="inventory-product-card-header">
                    <div className="inventory-product-card-type">
                      {typeof item.category === 'string' ? item.category : (item.category?.name || item.category || 'Uncategorized')}
                    </div>
                    <div className={`inventory-condition-badge ${(typeof item.condition === 'string' ? item.condition : (item.condition?.name || item.condition || 'Good')).toLowerCase()}`}>
                      {typeof item.condition === 'string' ? item.condition : (item.condition?.name || item.condition || 'Good')}
                    </div>
                  </div>
                  <div className="inventory-product-card-name">{item.name || 'Unnamed Equipment'}</div>
                  <div className="inventory-product-card-id">{item.equipment_id || item.id}</div>
                  <div className="inventory-product-card-stats">
                    <div className="inventory-product-card-stat">
                      <span className="label">Total</span>
                      <span className="value">{item.total_quantity || 0}</span>
                    </div>
                    <div className="inventory-product-card-stat">
                      <span className="label">Available</span>
                      <span className="value" style={{ color: '#10b981' }}>{item.available || 0}</span>
                    </div>
                    <div className="inventory-product-card-stat">
                      <span className="label">In Use</span>
                      <span className="value" style={{ color: '#f59e0b' }}>{item.in_use || 0}</span>
                    </div>
                    <div className="inventory-product-card-stat">
                      <span className="label">Damaged</span>
                      <span className="value" style={{ color: '#ef4444' }}>{item.damaged || 0}</span>
                    </div>
                  </div>
                  <div className="inventory-product-card-actions">
                    <motion.button onClick={() => handleViewDetails(item)} whileHover={{ scale: 1.1 }}><FiEye /></motion.button>
                    <motion.button onClick={() => handleEdit(item)} whileHover={{ scale: 1.1 }}><FiEdit2 /></motion.button>
                    <motion.button whileHover={{ scale: 1.1 }}><FiTool /></motion.button>
                  </div>
                </motion.div>
              ))}
            </div>
          )}

          {paginatedItems.length === 0 && (
            <motion.div className="inventory-empty-state" initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }}>
              <AiFillProduct className="inventory-empty-icon" />
              <h3>No equipment found</h3>
              <p>Try adjusting your search or filter criteria</p>
              <motion.button className="inventory-clear-filters-btn" whileHover={{ scale: 1.05 }} whileTap={{ scale: 0.95 }} onClick={() => {
                setSearchTerm('');
                setSelectedCategory('all');
                setCurrentPage(1);
              }}>Clear Filters</motion.button>
            </motion.div>
          )}
          
          {currentItems.length > 0 && (
            <motion.div className="inventory-pagination" initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.4 }}>
              <div className="inventory-pagination-info">
                Showing {(currentPage - 1) * ITEMS_PER_PAGE + 1} to {Math.min(currentPage * ITEMS_PER_PAGE, currentItems.length)} of {currentItems.length} items
              </div>
              <div className="inventory-pagination-controls">
                <motion.button className="inventory-pagination-btn" onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))} disabled={currentPage === 1} whileHover={{ scale: 1.1 }} whileTap={{ scale: 0.9 }}>
                  <FiChevronLeft />
                </motion.button>
                {[...Array(Math.min(totalPages, 5))].map((_, i) => {
                  let pageNum;
                  if (totalPages <= 5) {
                    pageNum = i + 1;
                  } else if (currentPage <= 3) {
                    pageNum = i + 1;
                  } else if (currentPage >= totalPages - 2) {
                    pageNum = totalPages - 4 + i;
                  } else {
                    pageNum = currentPage - 2 + i;
                  }
                  return (
                    <motion.button key={i} className={`inventory-pagination-btn ${currentPage === pageNum ? 'active' : ''}`} onClick={() => setCurrentPage(pageNum)} whileHover={{ scale: 1.1 }} whileTap={{ scale: 0.9 }}>
                      {pageNum}
                    </motion.button>
                  );
                })}
                <motion.button className="inventory-pagination-btn" onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))} disabled={currentPage === totalPages} whileHover={{ scale: 1.1 }} whileTap={{ scale: 0.9 }}>
                  <FiChevronRight />
                </motion.button>
              </div>
            </motion.div>
          )}
        </div>
      )}

      {/* ============================================================
          CONTENT - EQUIPMENT RESERVATIONS
          ============================================================ */}
      {activeModule === 'equipment' && activeEquipmentSubModule === 'equipment-reservations' && (
        <div className="inventory-equipment-reservations">
          <div className="inventory-reservations-header">
            <h4>Current Equipment Reservations</h4>
          </div>
          <div className="inventory-reservations-table-container">
            <table className="inventory-table">
              <thead>
                <tr>
                  <th>Reservation ID</th>
                  <th>Equipment</th>
                  <th>Quantity</th>
                  <th>Event</th>
                  <th>Start Date</th>
                  <th>End Date</th>
                  <th>Status</th>
                  <th>Reserved By</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {equipmentReservations.map(reservation => (
                  <tr key={reservation.id || reservation.booking_equipment_id}>
                    <td><span className="inventory-item-id">{reservation.id || reservation.booking_equipment_id}</span></td>
                    <td>{reservation.equipment?.name || reservation.equipment_name || 'Unknown'}</td>
                    <td>{reservation.quantity_reserved || reservation.quantity || 0}</td>
                    <td>{reservation.eventId || reservation.booking_id || 'N/A'}</td>
                    <td>{new Date(reservation.rental_start_date || reservation.startDate).toLocaleDateString()}</td>
                    <td>{new Date(reservation.rental_end_date || reservation.endDate).toLocaleDateString()}</td>
                    <td>
                      <div className={`inventory-reservation-status ${reservation.status || 'pending'}`}>
                        <FiClock />
                        <span>{reservation.status || 'Pending'}</span>
                      </div>
                    </td>
                    <td>{reservation.reservedBy || reservation.reserver?.name || 'System'}</td>
                    <td>
                      <div className="inventory-action-buttons">
                        <button className="inventory-action-icon-btn"><FiEye /></button>
                        <button className="inventory-action-icon-btn"><FiEdit2 /></button>
                        <button className="inventory-action-icon-btn"><BsArrowReturnLeft /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ============================================================
          CONTENT - EQUIPMENT MAINTENANCE
          ============================================================ */}
      {activeModule === 'equipment' && activeEquipmentSubModule === 'equipment-maintenance' && (
        <div className="inventory-maintenance">
          <div className="inventory-maintenance-header">
            <h3><FiTool /> Equipment Maintenance Schedule</h3>
            <p>Track maintenance tasks and schedules</p>
          </div>
          
          <div className="inventory-maintenance-grid">
            {equipment.filter(e => e.condition === 'Fair' || e.condition === 'Poor').map(item => (
              <div key={item.equipment_id} className="inventory-maintenance-card">
                <div className="inventory-maintenance-icon">
                  <FiTool />
                </div>
                <div className="inventory-maintenance-info">
                  <h4>{item.name}</h4>
                  <p>Condition: {item.condition}</p>
                  <p>Last Maintenance: {item.last_maintenance || 'Not recorded'}</p>
                  <button className="inventory-maintenance-btn">Schedule Maintenance</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ============================================================
          MODALS
          ============================================================ */}

      {/* ============================================================
          ADD PRODUCT MODAL
          ============================================================ */}
      <AnimatePresence>
        {showAddProductModal && (
          <motion.div 
            className="inventory-modal-overlay"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={() => setShowAddProductModal(false)}
          >
            <motion.div 
              className="inventory-modal-content inventory-add-modal"
              initial={{ scale: 0.8, y: 50 }}
              animate={{ scale: 1, y: 0 }}
              exit={{ scale: 0.8, y: 50 }}
              onClick={e => e.stopPropagation()}
            >
              <div className="inventory-modal-header">
                <h2><FiPackage /> Add New Ingredient</h2>
                <motion.button
                  className="inventory-close-modal"
                  onClick={() => setShowAddProductModal(false)}
                  whileHover={{ scale: 1.1, rotate: 90 }}
                  whileTap={{ scale: 0.9 }}
                >
                  <FiXCircle />
                </motion.button>
              </div>

              <div className="inventory-modal-body">
                <div className="inventory-add-form">
                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiPackage /> Ingredient Name *</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newProduct.name || ''}
                        onChange={(e) => setNewProduct({...newProduct, name: e.target.value})}
                        placeholder="Enter ingredient name"
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiTag /> SKU</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newProduct.sku || ''}
                        onChange={(e) => setNewProduct({...newProduct, sku: e.target.value})}
                        placeholder="Stock keeping unit"
                      />
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiLayers /> Category *</label>
                      <select
                        className="inventory-form-control"
                        value={newProduct.category || ''}
                        onChange={(e) => setNewProduct({...newProduct, category: e.target.value})}
                      >
                        <option value="">Select Category</option>
                        {categories
                          .filter(c => c.type === 'product')
                          .map(cat => (
                            <option key={cat.id} value={cat.name}>
                              {cat.icon} {cat.name}
                            </option>
                          ))}
                      </select>
                    </div>
                    <div className="inventory-form-group">
                      <label><FiBox /> Ingredient Type *</label>
                      <select
                        className="inventory-form-control"
                        value={newProduct.ingredientType || 'direct'}
                        onChange={(e) => setNewProduct({...newProduct, ingredientType: e.target.value})}
                      >
                        <option value="direct">Direct Ingredient (Exact computation)</option>
                        <option value="reusable">Reusable Ingredient (With reuse factor)</option>
                        <option value="estimated">Estimated Ingredient (Standardized estimation)</option>
                      </select>
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiPackage /> Quantity *</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newProduct.quantity || ''}
                        onChange={(e) => setNewProduct({...newProduct, quantity: e.target.value})}
                        placeholder="Current stock"
                        min="0"
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiBox /> Unit</label>
                      <select
                        className="inventory-form-control"
                        value={newProduct.unit || 'kg'}
                        onChange={(e) => setNewProduct({...newProduct, unit: e.target.value})}
                      >
                        <option value="kg">Kilograms (kg)</option>
                        <option value="g">Grams (g)</option>
                        <option value="L">Liters (L)</option>
                        <option value="ml">Milliliters (ml)</option>
                        <option value="pcs">Pieces (pcs)</option>
                        <option value="boxes">Boxes</option>
                        <option value="bags">Bags</option>
                      </select>
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiAlertCircle /> Min Stock</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newProduct.minStock || ''}
                        onChange={(e) => setNewProduct({...newProduct, minStock: e.target.value})}
                        placeholder="Minimum stock level"
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiTrendingUp /> Max Stock</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newProduct.maxStock || ''}
                        onChange={(e) => setNewProduct({...newProduct, maxStock: e.target.value})}
                        placeholder="Maximum stock level"
                      />
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiShoppingBag /> Reorder Point</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newProduct.reorderPoint || ''}
                        onChange={(e) => setNewProduct({...newProduct, reorderPoint: e.target.value})}
                        placeholder="Trigger reorder at"
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiTruck /> Supplier</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newProduct.supplier || ''}
                        onChange={(e) => setNewProduct({...newProduct, supplier: e.target.value})}
                        placeholder="Supplier name"
                      />
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiCalendar /> Expiry Date</label>
                      <input
                        type="date"
                        className="inventory-form-control"
                        value={newProduct.expiryDate || ''}
                        onChange={(e) => setNewProduct({...newProduct, expiryDate: e.target.value})}
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiDroplet /> Cost per Unit</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newProduct.costPerUnit || ''}
                        onChange={(e) => setNewProduct({...newProduct, costPerUnit: e.target.value})}
                        placeholder="Cost per unit"
                        step="0.01"
                      />
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label>Yield Percentage (%)</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newProduct.yieldPercentage || 100}
                        onChange={(e) => setNewProduct({...newProduct, yieldPercentage: e.target.value})}
                        placeholder="e.g., 80 for 80% yield"
                      />
                      <small>For direct ingredients - how much is usable after prep</small>
                    </div>
                    <div className="inventory-form-group">
                      <label>Reuse Factor</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newProduct.reuseFactor || 1}
                        onChange={(e) => setNewProduct({...newProduct, reuseFactor: e.target.value})}
                        placeholder="e.g., 3 for 3 uses"
                        step="0.1"
                      />
                      <small>For reusable ingredients - how many times it can be reused</small>
                    </div>
                  </div>

                  <div className="inventory-form-group">
                    <label><BsFileText /> Notes</label>
                    <textarea
                      className="inventory-form-control"
                      rows="3"
                      value={newProduct.notes || ''}
                      onChange={(e) => setNewProduct({...newProduct, notes: e.target.value})}
                      placeholder="Additional notes..."
                    />
                  </div>
                </div>
              </div>

              <div className="inventory-modal-footer">
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-secondary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={() => setShowAddProductModal(false)}
                >
                  Cancel
                </motion.button>
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-primary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={handleAddProduct}
                >
                  Add Ingredient
                </motion.button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ============================================================
          ADD EQUIPMENT MODAL
          ============================================================ */}
      <AnimatePresence>
        {showAddEquipmentModal && (
          <motion.div 
            className="inventory-modal-overlay"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={() => setShowAddEquipmentModal(false)}
          >
            <motion.div 
              className="inventory-modal-content inventory-add-modal"
              initial={{ scale: 0.8, y: 50 }}
              animate={{ scale: 1, y: 0 }}
              exit={{ scale: 0.8, y: 50 }}
              onClick={e => e.stopPropagation()}
            >
              <div className="inventory-modal-header">
                <h2><GiSpoon /> Add New Equipment</h2>
                <motion.button
                  className="inventory-close-modal"
                  onClick={() => setShowAddEquipmentModal(false)}
                  whileHover={{ scale: 1.1, rotate: 90 }}
                  whileTap={{ scale: 0.9 }}
                >
                  <FiXCircle />
                </motion.button>
              </div>

              <div className="inventory-modal-body">
                <div className="inventory-add-form">
                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiPackage /> Equipment Name *</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newEquipment.name || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, name: e.target.value})}
                        placeholder="Enter equipment name"
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label>Model</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newEquipment.model || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, model: e.target.value})}
                        placeholder="Model (optional)"
                      />
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiLayers /> Main Category *</label>
                      <select
                        className="inventory-form-control"
                        value={newEquipment.category || ''}
                        onChange={(e) => {
                          setNewEquipment({...newEquipment, category: e.target.value, sub_category: ''});
                        }}
                      >
                        <option value="">Select Category</option>
                        {equipmentMainCategories.map(cat => (
                          <option key={cat} value={cat}>{cat}</option>
                        ))}
                      </select>
                    </div>
                    <div className="inventory-form-group">
                      <label><FiLayers /> Sub Category</label>
                      <select
                        className="inventory-form-control"
                        value={newEquipment.sub_category || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, sub_category: e.target.value})}
                        disabled={!newEquipment.category}
                      >
                        <option value="">Select Sub Category</option>
                        {getSubCategoriesByMain(newEquipment.category).map(cat => (
                          <option key={cat.id} value={cat.name}>
                            {cat.icon} {cat.name}
                          </option>
                        ))}
                      </select>
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label>Serial Number</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newEquipment.serial_number || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, serial_number: e.target.value})}
                        placeholder="Serial number (optional)"
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiBox /> Total Quantity *</label>
                      <input
                        type="number"
                        className="inventory-form-control"
                        value={newEquipment.total_quantity || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, total_quantity: e.target.value})}
                        placeholder="Total quantity"
                        min="1"
                      />
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiMapPin /> Location</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newEquipment.location || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, location: e.target.value})}
                        placeholder="Storage location"
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiTruck /> Supplier</label>
                      <input
                        type="text"
                        className="inventory-form-control"
                        value={newEquipment.supplier || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, supplier: e.target.value})}
                        placeholder="Supplier name"
                      />
                    </div>
                  </div>

                  <div className="inventory-form-row">
                    <div className="inventory-form-group">
                      <label><FiCalendar /> Last Maintenance</label>
                      <input
                        type="date"
                        className="inventory-form-control"
                        value={newEquipment.last_maintenance || ''}
                        onChange={(e) => setNewEquipment({...newEquipment, last_maintenance: e.target.value})}
                      />
                    </div>
                    <div className="inventory-form-group">
                      <label><FiCheckCircle /> Condition</label>
                      <select
                        className="inventory-form-control"
                        value={newEquipment.condition || 'Good'}
                        onChange={(e) => setNewEquipment({...newEquipment, condition: e.target.value})}
                      >
                        <option value="Excellent">Excellent</option>
                        <option value="Good">Good</option>
                        <option value="Fair">Fair</option>
                        <option value="Poor">Poor</option>
                      </select>
                    </div>
                  </div>

                  <div className="inventory-form-group">
                    <label><BsFileText /> Notes</label>
                    <textarea
                      className="inventory-form-control"
                      rows="3"
                      value={newEquipment.notes || ''}
                      onChange={(e) => setNewEquipment({...newEquipment, notes: e.target.value})}
                      placeholder="Additional notes..."
                    />
                  </div>
                </div>
              </div>

              <div className="inventory-modal-footer">
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-secondary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={() => setShowAddEquipmentModal(false)}
                >
                  Cancel
                </motion.button>
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-primary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={handleAddEquipment}
                >
                  Add Equipment
                </motion.button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ============================================================
          STOCK MOVEMENT MODAL
          ============================================================ */}
      <AnimatePresence>
  {showStockMovementModal && selectedItem && (
    <motion.div 
      className="inventory-modal-overlay"
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      onClick={() => setShowStockMovementModal(false)}
    >
      <motion.div 
        className="inventory-modal-content"
        initial={{ scale: 0.8, y: 50 }}
        animate={{ scale: 1, y: 0 }}
        exit={{ scale: 0.8, y: 50 }}
        onClick={e => e.stopPropagation()}
      >
        <div className="inventory-modal-header">
          <h2><BsCartPlus /> Record Stock Movement - {selectedItem.name}</h2>
          <motion.button
            className="inventory-close-modal"
            onClick={() => setShowStockMovementModal(false)}
            whileHover={{ scale: 1.1, rotate: 90 }}
            whileTap={{ scale: 0.9 }}
          >
            <FiXCircle />
          </motion.button>
        </div>

        <div className="inventory-modal-body">
          <div className="inventory-form-group">
            <label>Movement Type</label>
            <select 
              className="inventory-form-control"
              value={stockMovement.type}
              onChange={(e) => setStockMovement({...stockMovement, type: e.target.value})}
            >
              <option value="purchase">Purchase (Add Stock)</option>
              <option value="usage">Usage (Deduct Stock)</option>
              <option value="return">Return (Add Stock)</option>
              <option value="waste">Waste (Deduct Stock)</option>
              <option value="adjustment">Adjustment</option>
            </select>
          </div>

          <div className="inventory-form-group">
            <label>Quantity</label>
            <input
              type="number"
              className="inventory-form-control"
              value={stockMovement.quantity}
              onChange={(e) => setStockMovement({...stockMovement, quantity: e.target.value})}
              placeholder={`Enter quantity (${selectedItem.unit || 'units'})`}
              min="0"
              step="0.01"
            />
          </div>

          <div className="inventory-form-group">
            <label>Reference / Order Number</label>
            <input
              type="text"
              className="inventory-form-control"
              value={stockMovement.reference}
              onChange={(e) => setStockMovement({...stockMovement, reference: e.target.value})}
              placeholder="e.g., PO-12345"
            />
          </div>

          <div className="inventory-form-group">
            <label>Reason / Notes</label>
            <textarea
              className="inventory-form-control"
              rows="3"
              value={stockMovement.reason}
              onChange={(e) => setStockMovement({...stockMovement, reason: e.target.value})}
              placeholder="Reason for this movement"
            />
          </div>

          <div className="inventory-form-group">
            <label>Date</label>
            <input
              type="date"
              className="inventory-form-control"
              value={stockMovement.date}
              onChange={(e) => setStockMovement({...stockMovement, date: e.target.value})}
            />
          </div>
        </div>

        <div className="inventory-modal-footer">
          <motion.button
            className="inventory-modal-btn inventory-modal-btn-secondary"
            whileHover={{ scale: 1.05 }}
            whileTap={{ scale: 0.95 }}
            onClick={() => setShowStockMovementModal(false)}
          >
            Cancel
          </motion.button>
          <motion.button
            className="inventory-modal-btn inventory-modal-btn-primary"
            whileHover={{ scale: 1.05 }}
            whileTap={{ scale: 0.95 }}
            onClick={handleStockMovement}
            disabled={!stockMovement.quantity}
          >
            Record Movement
          </motion.button>
        </div>
      </motion.div>
    </motion.div>
  )}
</AnimatePresence>

      {/* ============================================================
          PURCHASE REQUEST MODAL
          ============================================================ */}
      <AnimatePresence>
        {showPurchaseRequestModal && (
          <motion.div 
            className="inventory-modal-overlay"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={() => setShowPurchaseRequestModal(false)}
          >
            <motion.div 
              className="inventory-modal-content"
              initial={{ scale: 0.8, y: 50 }}
              animate={{ scale: 1, y: 0 }}
              exit={{ scale: 0.8, y: 50 }}
              onClick={e => e.stopPropagation()}
            >
              <div className="inventory-modal-header">
                <h2><FiShoppingBag /> Create Purchase Request</h2>
                <motion.button
                  className="inventory-close-modal"
                  onClick={() => setShowPurchaseRequestModal(false)}
                  whileHover={{ scale: 1.1, rotate: 90 }}
                  whileTap={{ scale: 0.9 }}
                >
                  <FiXCircle />
                </motion.button>
              </div>

              <div className="inventory-modal-body">
                <div className="inventory-form-group">
                  <label>Ingredient</label>
                  <select 
                    className="inventory-form-control"
                    value={purchaseRequest.ingredientId}
                    onChange={(e) => setPurchaseRequest({...purchaseRequest, ingredientId: e.target.value})}
                  >
                    <option value="">Select Ingredient</option>
                    {products.filter(p => (p.quantity || 0) <= (p.reorder_point || 15)).map(product => (
                      <option key={product.product_id} value={product.product_id}>
                        {product.name} (Current: {product.quantity || 0} {product.unit})
                      </option>
                    ))}
                  </select>
                </div>

                <div className="inventory-form-group">
                  <label>Quantity to Order</label>
                  <input
                    type="number"
                    className="inventory-form-control"
                    value={purchaseRequest.quantity}
                    onChange={(e) => setPurchaseRequest({...purchaseRequest, quantity: e.target.value})}
                    placeholder="Enter quantity"
                    min="0"
                  />
                </div>

                <div className="inventory-form-group">
                  <label>Supplier</label>
                  <input
                    type="text"
                    className="inventory-form-control"
                    value={purchaseRequest.supplierId}
                    onChange={(e) => setPurchaseRequest({...purchaseRequest, supplierId: e.target.value})}
                    placeholder="Supplier name"
                  />
                </div>

                <div className="inventory-form-group">
                  <label>Urgency</label>
                  <select 
                    className="inventory-form-control"
                    value={purchaseRequest.urgency}
                    onChange={(e) => setPurchaseRequest({...purchaseRequest, urgency: e.target.value})}
                  >
                    <option value="normal">Normal (3-5 days)</option>
                    <option value="urgent">Urgent (1-2 days)</option>
                    <option value="critical">Critical (Same day)</option>
                  </select>
                </div>

                <div className="inventory-form-group">
                  <label>Expected Delivery Date</label>
                  <input
                    type="date"
                    className="inventory-form-control"
                    value={purchaseRequest.expectedDelivery}
                    onChange={(e) => setPurchaseRequest({...purchaseRequest, expectedDelivery: e.target.value})}
                  />
                </div>

                <div className="inventory-form-group">
                  <label>Notes</label>
                  <textarea
                    className="inventory-form-control"
                    rows="3"
                    value={purchaseRequest.notes}
                    onChange={(e) => setPurchaseRequest({...purchaseRequest, notes: e.target.value})}
                    placeholder="Additional notes..."
                  />
                </div>
              </div>

              <div className="inventory-modal-footer">
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-secondary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={() => setShowPurchaseRequestModal(false)}
                >
                  Cancel
                </motion.button>
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-primary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={handlePurchaseRequest}
                  disabled={!purchaseRequest.ingredientId || !purchaseRequest.quantity}
                >
                  Create Request
                </motion.button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ============================================================
          WASTE RECORD MODAL - COMPLETE FIX
          ============================================================ */}
     {/* WASTE RECORD MODAL - COMPLETE FIX */}
<AnimatePresence>
  {showWasteRecordModal && (
    <motion.div 
      className="inventory-modal-overlay"
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      onClick={() => setShowWasteRecordModal(false)}
    >
      <motion.div 
        className="inventory-modal-content"
        initial={{ scale: 0.8, y: 50 }}
        animate={{ scale: 1, y: 0 }}
        exit={{ scale: 0.8, y: 50 }}
        onClick={e => e.stopPropagation()}
      >
        <div className="inventory-modal-header">
          <h2><FiTrash2 /> Record Waste / Spoilage</h2>
          <motion.button
            className="inventory-close-modal"
            onClick={() => setShowWasteRecordModal(false)}
            whileHover={{ scale: 1.1, rotate: 90 }}
            whileTap={{ scale: 0.9 }}
          >
            <FiXCircle />
          </motion.button>
        </div>

        <div className="inventory-modal-body">
          {/* ✅ FIXED: Only store the ID, not the object */}
          <div className="inventory-form-group">
            <label>Ingredient</label>
            <select 
              className="inventory-form-control"
              value={String(wasteRecord.ingredientId || '')}
              onChange={(e) => {
                setWasteRecord(prev => ({
                  ...prev, 
                  ingredientId: e.target.value
                }));
              }}
            >
              <option value="">Select Ingredient</option>
              {products && products.length > 0 ? (
                products.map(product => (
                  <option 
                    key={product.product_id} 
                    value={String(product.product_id)}
                  >
                    {product.name} (Stock: {product.quantity || 0} {product.unit || 'units'})
                  </option>
                ))
              ) : (
                <option value="">No ingredients available</option>
              )}
            </select>
          </div>

          <div className="inventory-form-group">
            <label>Quantity Wasted</label>
            <input
              type="number"
              className="inventory-form-control"
              value={wasteRecord.quantity}
              onChange={(e) => setWasteRecord({...wasteRecord, quantity: e.target.value})}
              placeholder="Enter quantity"
              min="0"
              step="0.01"
            />
          </div>

          <div className="inventory-form-group">
            <label>Reason</label>
            <select 
              className="inventory-form-control"
              value={wasteRecord.reason}
              onChange={(e) => setWasteRecord({...wasteRecord, reason: e.target.value})}
            >
              <option value="spoilage">Spoilage</option>
              <option value="expired">Expired</option>
              <option value="damage">Physical Damage</option>
              <option value="prep_waste">Preparation Waste</option>
              <option value="other">Other</option>
            </select>
          </div>

          <div className="inventory-form-group">
            <label>Notes</label>
            <textarea
              className="inventory-form-control"
              rows="3"
              value={wasteRecord.notes}
              onChange={(e) => setWasteRecord({...wasteRecord, notes: e.target.value})}
              placeholder="Additional notes..."
            />
          </div>
        </div>

        <div className="inventory-modal-footer">
          <motion.button
            className="inventory-modal-btn inventory-modal-btn-secondary"
            whileHover={{ scale: 1.05 }}
            whileTap={{ scale: 0.95 }}
            onClick={() => setShowWasteRecordModal(false)}
          >
            Cancel
          </motion.button>
          <motion.button
            className="inventory-modal-btn inventory-modal-btn-primary"
            whileHover={{ scale: 1.05 }}
            whileTap={{ scale: 0.95 }}
            onClick={handleRecordWaste}
            disabled={!wasteRecord.ingredientId || !wasteRecord.quantity}
          >
            Record Waste
          </motion.button>
        </div>
      </motion.div>
    </motion.div>
  )}
</AnimatePresence>

      {/* ============================================================
          EQUIPMENT RESERVATION MODAL
          ============================================================ */}
      <AnimatePresence>
        {showEquipmentReservationModal && (
          <motion.div 
            className="inventory-modal-overlay"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={() => setShowEquipmentReservationModal(false)}
          >
            <motion.div 
              className="inventory-modal-content"
              initial={{ scale: 0.8, y: 50 }}
              animate={{ scale: 1, y: 0 }}
              exit={{ scale: 0.8, y: 50 }}
              onClick={e => e.stopPropagation()}
            >
              <div className="inventory-modal-header">
                <h2><FiClock /> Reserve Equipment</h2>
                <motion.button
                  className="inventory-close-modal"
                  onClick={() => setShowEquipmentReservationModal(false)}
                  whileHover={{ scale: 1.1, rotate: 90 }}
                  whileTap={{ scale: 0.9 }}
                >
                  <FiXCircle />
                </motion.button>
              </div>

              <div className="inventory-modal-body">
                <div className="inventory-form-group">
                  <label>Equipment</label>
                  <select 
                    className="inventory-form-control"
                    value={equipmentReservation.equipmentId}
                    onChange={(e) => setEquipmentReservation({...equipmentReservation, equipmentId: e.target.value})}
                  >
                    <option value="">Select Equipment</option>
                    {equipment.map(item => (
                      <option key={item.equipment_id} value={item.equipment_id}>
                        {item.name} (Available: {item.available || 0} of {item.total_quantity || 0})
                      </option>
                    ))}
                  </select>
                </div>

                <div className="inventory-form-group">
                  <label>Quantity to Reserve</label>
                  <input
                    type="number"
                    className="inventory-form-control"
                    value={equipmentReservation.quantity}
                    onChange={(e) => setEquipmentReservation({...equipmentReservation, quantity: e.target.value})}
                    placeholder="Enter quantity"
                    min="1"
                  />
                </div>

                <div className="inventory-form-group">
                  <label>Event / Purpose</label>
                  <input
                    type="text"
                    className="inventory-form-control"
                    value={equipmentReservation.eventId}
                    onChange={(e) => setEquipmentReservation({...equipmentReservation, eventId: e.target.value})}
                    placeholder="Event name or purpose"
                  />
                </div>

                <div className="inventory-form-row">
                  <div className="inventory-form-group">
                    <label>Start Date</label>
                    <input
                      type="date"
                      className="inventory-form-control"
                      value={equipmentReservation.startDate}
                      onChange={(e) => setEquipmentReservation({...equipmentReservation, startDate: e.target.value})}
                    />
                  </div>
                  <div className="inventory-form-group">
                    <label>End Date</label>
                    <input
                      type="date"
                      className="inventory-form-control"
                      value={equipmentReservation.endDate}
                      onChange={(e) => setEquipmentReservation({...equipmentReservation, endDate: e.target.value})}
                    />
                  </div>
                </div>

                <div className="inventory-form-group">
                  <label>Notes</label>
                  <textarea
                    className="inventory-form-control"
                    rows="3"
                    value={equipmentReservation.notes}
                    onChange={(e) => setEquipmentReservation({...equipmentReservation, notes: e.target.value})}
                    placeholder="Special instructions..."
                  />
                </div>
              </div>

              <div className="inventory-modal-footer">
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-secondary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={() => setShowEquipmentReservationModal(false)}
                >
                  Cancel
                </motion.button>
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-primary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={handleEquipmentReservation}
                  disabled={!equipmentReservation.equipmentId || !equipmentReservation.quantity}
                >
                  Reserve Equipment
                </motion.button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ============================================================
          EDIT MODAL
          ============================================================ */}
      <AnimatePresence>
        {showEditModal && editingItem && (
          <motion.div 
            className="inventory-modal-overlay"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={() => {
              setShowEditModal(false);
              setEditingItem(null);
            }}
          >
            <motion.div 
              className="inventory-modal-content inventory-edit-modal"
              initial={{ scale: 0.8, y: 50 }}
              animate={{ scale: 1, y: 0 }}
              exit={{ scale: 0.8, y: 50 }}
              onClick={e => e.stopPropagation()}
            >
              <div className="inventory-modal-header">
                <h2>
                  {activeModule === 'products' ? <FiPackage /> : <GiSpoon />} 
                  Edit {activeModule === 'products' ? 'Product' : 'Equipment'}
                </h2>
                <motion.button
                  className="inventory-close-modal"
                  onClick={() => {
                    setShowEditModal(false);
                    setEditingItem(null);
                  }}
                  whileHover={{ scale: 1.1, rotate: 90 }}
                  whileTap={{ scale: 0.9 }}
                >
                  <FiXCircle />
                </motion.button>
              </div>

              <div className="inventory-modal-body">
                <div className="inventory-edit-form">
                  {activeModule === 'products' ? (
                    <>
                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Product Name *</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editProduct.name || ''}
                            onChange={(e) => setEditProduct({...editProduct, name: e.target.value})}
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>SKU</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editProduct.sku || ''}
                            onChange={(e) => setEditProduct({...editProduct, sku: e.target.value})}
                          />
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Category *</label>
                          <select
                            className="inventory-form-control"
                            value={editProduct.category || ''}
                            onChange={(e) => setEditProduct({...editProduct, category: e.target.value})}
                          >
                            {categories
                              .filter(c => c.type === 'product')
                              .map(cat => (
                                <option key={cat.id} value={cat.name}>
                                  {cat.icon} {cat.name}
                                </option>
                              ))}
                          </select>
                        </div>
                        <div className="inventory-form-group">
                          <label>Ingredient Type</label>
                          <select
                            className="inventory-form-control"
                            value={editProduct.ingredientType || 'direct'}
                            onChange={(e) => setEditProduct({...editProduct, ingredientType: e.target.value})}
                          >
                            <option value="direct">Direct Ingredient</option>
                            <option value="reusable">Reusable Ingredient</option>
                            <option value="estimated">Estimated Ingredient</option>
                          </select>
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Quantity *</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editProduct.quantity || ''}
                            onChange={(e) => setEditProduct({...editProduct, quantity: e.target.value})}
                            min="0"
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Unit</label>
                          <select
                            className="inventory-form-control"
                            value={editProduct.unit || 'kg'}
                            onChange={(e) => setEditProduct({...editProduct, unit: e.target.value})}
                          >
                            <option value="kg">Kilograms (kg)</option>
                            <option value="g">Grams (g)</option>
                            <option value="L">Liters (L)</option>
                            <option value="ml">Milliliters (ml)</option>
                            <option value="pcs">Pieces (pcs)</option>
                          </select>
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Min Stock</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editProduct.minStock || ''}
                            onChange={(e) => setEditProduct({...editProduct, minStock: e.target.value})}
                            min="0"
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Max Stock</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editProduct.maxStock || ''}
                            onChange={(e) => setEditProduct({...editProduct, maxStock: e.target.value})}
                            min="0"
                          />
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Reorder Point</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editProduct.reorderPoint || ''}
                            onChange={(e) => setEditProduct({...editProduct, reorderPoint: e.target.value})}
                            min="0"
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Supplier</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editProduct.supplier || ''}
                            onChange={(e) => setEditProduct({...editProduct, supplier: e.target.value})}
                          />
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Expiry Date</label>
                          <input
                            type="date"
                            className="inventory-form-control"
                            value={editProduct.expiryDate || ''}
                            onChange={(e) => setEditProduct({...editProduct, expiryDate: e.target.value})}
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Cost per Unit</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editProduct.costPerUnit || ''}
                            onChange={(e) => setEditProduct({...editProduct, costPerUnit: e.target.value})}
                            min="0"
                            step="0.01"
                          />
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Yield Percentage (%)</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editProduct.yieldPercentage || 100}
                            onChange={(e) => setEditProduct({...editProduct, yieldPercentage: e.target.value})}
                            min="1"
                            max="100"
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Reuse Factor</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editProduct.reuseFactor || 1}
                            onChange={(e) => setEditProduct({...editProduct, reuseFactor: e.target.value})}
                            min="0"
                            step="0.1"
                          />
                        </div>
                      </div>

                      <div className="inventory-form-group">
                        <label>Notes</label>
                        <textarea
                          className="inventory-form-control"
                          rows="2"
                          value={editProduct.notes || ''}
                          onChange={(e) => setEditProduct({...editProduct, notes: e.target.value})}
                        />
                      </div>

                      <div className="inventory-form-group">
                        <label className="inventory-checkbox-label">
                          <input
                            type="checkbox"
                            checked={editProduct.active || false}
                            onChange={(e) => setEditProduct({...editProduct, active: e.target.checked})}
                          />
                          <span>Active (visible in inventory)</span>
                        </label>
                      </div>
                    </>
                  ) : (
                    <>
                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Equipment Name *</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editEquipment.name || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, name: e.target.value})}
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Model</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editEquipment.model || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, model: e.target.value})}
                          />
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Category *</label>
                          <select
                            className="inventory-form-control"
                            value={editEquipment.category || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, category: e.target.value})}
                          >
                            {equipmentMainCategories.map(cat => (
                              <option key={cat} value={cat}>{cat}</option>
                            ))}
                          </select>
                        </div>
                        <div className="inventory-form-group">
                          <label>Sub Category</label>
                          <select
                            className="inventory-form-control"
                            value={editEquipment.sub_category || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, sub_category: e.target.value})}
                            disabled={!editEquipment.category}
                          >
                            <option value="">Select Sub Category</option>
                            {getSubCategoriesByMain(editEquipment.category).map(cat => (
                              <option key={cat.id} value={cat.name}>
                                {cat.icon} {cat.name}
                              </option>
                            ))}
                          </select>
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Serial Number</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editEquipment.serial_number || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, serial_number: e.target.value})}
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Total Quantity *</label>
                          <input
                            type="number"
                            className="inventory-form-control"
                            value={editEquipment.total_quantity || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, total_quantity: e.target.value})}
                            min="0"
                          />
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Location</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editEquipment.location || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, location: e.target.value})}
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Supplier</label>
                          <input
                            type="text"
                            className="inventory-form-control"
                            value={editEquipment.supplier || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, supplier: e.target.value})}
                          />
                        </div>
                      </div>

                      <div className="inventory-form-row">
                        <div className="inventory-form-group">
                          <label>Last Maintenance</label>
                          <input
                            type="date"
                            className="inventory-form-control"
                            value={editEquipment.last_maintenance || ''}
                            onChange={(e) => setEditEquipment({...editEquipment, last_maintenance: e.target.value})}
                          />
                        </div>
                        <div className="inventory-form-group">
                          <label>Condition</label>
                          <select
                            className="inventory-form-control"
                            value={editEquipment.condition || 'Good'}
                            onChange={(e) => setEditEquipment({...editEquipment, condition: e.target.value})}
                          >
                            <option value="Excellent">Excellent</option>
                            <option value="Good">Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                          </select>
                        </div>
                      </div>

                      <div className="inventory-form-group">
                        <label>Notes</label>
                        <textarea
                          className="inventory-form-control"
                          rows="2"
                          value={editEquipment.notes || ''}
                          onChange={(e) => setEditEquipment({...editEquipment, notes: e.target.value})}
                        />
                      </div>

                      <div className="inventory-form-group">
                        <label className="inventory-checkbox-label">
                          <input
                            type="checkbox"
                            checked={editEquipment.active || false}
                            onChange={(e) => setEditEquipment({...editEquipment, active: e.target.checked})}
                          />
                          <span>Active (visible in inventory)</span>
                        </label>
                      </div>
                    </>
                  )}
                </div>
              </div>

              <div className="inventory-modal-footer">
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-secondary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={() => {
                    setShowEditModal(false);
                    setEditingItem(null);
                  }}
                >
                  Cancel
                </motion.button>
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-primary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={handleUpdateItem}
                >
                  Update
                </motion.button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ============================================================
          ITEM DETAILS MODAL
          ============================================================ */}
      <AnimatePresence>
        {showItemDetails && selectedItem && (
          <motion.div 
            className="inventory-modal-overlay"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={() => setShowItemDetails(false)}
          >
            <motion.div 
              className="inventory-modal-content"
              initial={{ scale: 0.8, y: 50 }}
              animate={{ scale: 1, y: 0 }}
              exit={{ scale: 0.8, y: 50 }}
              onClick={e => e.stopPropagation()}
            >
              <div className="inventory-modal-header">
                <h2>Item Details - {selectedItem?.product_id || selectedItem?.equipment_id}</h2>
                <motion.button
                  className="inventory-close-modal"
                  onClick={() => setShowItemDetails(false)}
                  whileHover={{ scale: 1.1, rotate: 90 }}
                  whileTap={{ scale: 0.9 }}
                >
                  <FiXCircle />
                </motion.button>
              </div>

              <div className="inventory-modal-body">
                <div className="inventory-modal-section">
                  <h3><FiBox /> Basic Information</h3>
                  <div className="inventory-detail-grid">
                    <div className="inventory-detail-item">
                      <span className="inventory-detail-label">ID</span>
                      <span className="inventory-detail-value inventory-detail-value-highlight">
                        {selectedItem?.product_id || selectedItem?.equipment_id}
                      </span>
                    </div>
                    <div className="inventory-detail-item">
                      <span className="inventory-detail-label">Name</span>
                      <span className="inventory-detail-value">{selectedItem?.name}</span>
                    </div>
                    {'sku' in selectedItem && (
                      <div className="inventory-detail-item">
                        <span className="inventory-detail-label">SKU</span>
                        <span className="inventory-detail-value">{selectedItem?.sku || '-'}</span>
                      </div>
                    )}
                    <div className="inventory-detail-item">
                      <span className="inventory-detail-label">Category</span>
                      <span className="inventory-detail-value">{selectedItem?.category}</span>
                    </div>
                    {'ingredient_type' in selectedItem && (
                      <div className="inventory-detail-item">
                        <span className="inventory-detail-label">Ingredient Type</span>
                        <span className="inventory-detail-value">{ingredientTypes[selectedItem.ingredient_type]?.name || 'Direct'}</span>
                      </div>
                    )}
                    <div className="inventory-detail-item">
                      <span className="inventory-detail-label">Status</span>
                      <span className={`inventory-detail-value ${selectedItem?.status}`}>
                        {getStatusDetails(selectedItem?.status).text}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="inventory-modal-section">
                  <h3><FiPackage /> Stock Information</h3>
                  <div className="inventory-detail-grid">
                    {'quantity' in selectedItem && (
                      <>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Current Stock</span>
                          <span className={`inventory-detail-value ${selectedItem?.status}`}>
                            {selectedItem?.quantity} {selectedItem?.unit}
                          </span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Reserved</span>
                          <span className="inventory-detail-value">{selectedItem?.reserved || 0}</span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Available</span>
                          <span className="inventory-detail-value">{(selectedItem?.quantity || 0) - (selectedItem?.reserved || 0)}</span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Min Stock</span>
                          <span className="inventory-detail-value">{selectedItem?.min_stock || 10}</span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Max Stock</span>
                          <span className="inventory-detail-value">{selectedItem?.max_stock || 100}</span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Reorder Point</span>
                          <span className="inventory-detail-value">{selectedItem?.reorder_point || 15}</span>
                        </div>
                      </>
                    )}
                    
                    {'total_quantity' in selectedItem && (
                      <>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Total Quantity</span>
                          <span className="inventory-detail-value">{selectedItem?.total_quantity}</span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">In Use</span>
                          <span className="inventory-detail-value inventory-detail-value-warning">{selectedItem?.in_use || 0}</span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Available</span>
                          <span className="inventory-detail-value inventory-detail-value-success">{selectedItem?.available || 0}</span>
                        </div>
                        <div className="inventory-detail-item">
                          <span className="inventory-detail-label">Reserved</span>
                          <span className="inventory-detail-value inventory-detail-value-info">{selectedItem?.reserved || 0}</span>
                        </div>
                      </>
                    )}
                  </div>
                </div>

                <div className="inventory-modal-section">
                  <h3><MdHistory /> Recent History</h3>
                  <div className="inventory-history-timeline">
                    <p className="inventory-no-history">No recent history for this item</p>
                  </div>
                </div>
              </div>

              <div className="inventory-modal-footer">
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-secondary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={() => setShowItemDetails(false)}
                >
                  Close
                </motion.button>
                <motion.button
                  className="inventory-modal-btn inventory-modal-btn-primary"
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  onClick={() => {
                    setShowItemDetails(false);
                    handleEdit(selectedItem);
                  }}
                >
                  <FiEdit2 />
                  Edit
                </motion.button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

    </div>
  );
};

export default Inventory;