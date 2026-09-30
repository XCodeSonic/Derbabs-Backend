  // src/hooks/useInventoryQueries.js
  import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
  import { message } from 'antd';
  import api, { inventoryAPI } from '../services/api';

  // Query Keys
  export const inventoryKeys = {
    products: {
      all: ['products'],
      lists: () => [...inventoryKeys.products.all, 'list'],
      list: (filters) => [...inventoryKeys.products.lists(), { filters }],
      details: () => [...inventoryKeys.products.all, 'detail'],
      detail: (id) => [...inventoryKeys.products.details(), id],
      stats: () => [...inventoryKeys.products.all, 'stats'],
    },
    equipment: {
      all: ['equipment'],
      lists: () => [...inventoryKeys.equipment.all, 'list'],
      list: (filters) => [...inventoryKeys.equipment.lists(), { filters }],
      details: () => [...inventoryKeys.equipment.all, 'detail'],
      detail: (id) => [...inventoryKeys.equipment.details(), id],
      stats: () => [...inventoryKeys.equipment.all, 'stats'],
      history: (id) => ['equipment', 'history', id],
    },
    movements: {
      all: ['movements'],
      list: (filters) => ['movements', filters],
    },
    waste: {
      all: ['waste'],
      list: (filters) => ['waste', filters],
    },
    purchaseRequests: {
      all: ['purchase-requests'],
      list: (filters) => ['purchase-requests', filters],
    },
    suppliers: {
      all: ['suppliers'],
      list: (filters) => ['suppliers', filters],
    },
    reservations: {
      all: ['reservations'],
      list: (filters) => ['reservations', filters],
    },
    dashboard: {
      stats: ['dashboard-stats'],
    },
    history: {
      item: (type, id) => ['history', type, id],
    },
    lowStock: {
      all: ['low-stock'],
      list: (filters) => ['low-stock', filters],
    },
    expiringSoon: {
      all: ['expiring-soon'],
    },
  };

  // ==================== LOW STOCK ====================

  export const useLowStockItems = (params = {}) => {
    return useQuery({
      queryKey: inventoryKeys.lowStock.list(params),
      queryFn: () => api.get('/ingredients/low-stock', { params }),
      select: (response) => {
        const data = response?.data?.data;
        if (Array.isArray(data)) return data;
        if (data?.data && Array.isArray(data.data)) return data.data;
        return [];
      },
      staleTime: 2 * 60 * 1000,
      refetchInterval: 5 * 60 * 1000,
    });
  };

  export const useExpiringSoonItems = () => {
    return useQuery({
      queryKey: inventoryKeys.expiringSoon.all,
      queryFn: () => api.get('/inventory/expiring-soon'),
      select: (response) => response?.data?.data || [],
      staleTime: 5 * 60 * 1000,
    });
  };

  // ==================== PRODUCTS ====================

  export const useProducts = (filters = {}) => {
    return useQuery({
      queryKey: inventoryKeys.products.list(filters),
      queryFn: () => inventoryAPI.getProducts(filters),
      select: (response) => {
        const data = response.data.data;
        return {
          data: data.data || [],
          total: data.total || 0,
          current_page: data.current_page || 1,
          last_page: data.last_page || 1,
          per_page: data.per_page || 15
        };
      },
      staleTime: 5 * 60 * 1000,
      keepPreviousData: true
    });
  };

  export const useProductStats = () => {
    return useQuery({
      queryKey: inventoryKeys.products.stats(),
      queryFn: () => inventoryAPI.getProductStats(),
      select: (response) => response.data.data,
      staleTime: 5 * 60 * 1000,
    });
  };

  export const useProduct = (id) => {
    return useQuery({
      queryKey: inventoryKeys.products.detail(id),
      queryFn: () => inventoryAPI.getProduct(id),
      select: (response) => response.data.data,
      enabled: !!id,
      staleTime: 5 * 60 * 1000,
    });
  };

  export const useCreateProduct = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (data) => inventoryAPI.createProduct(data),
      onSuccess: () => {
        message.success('Product created successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to create product');
      }
    });
  };

  export const useUpdateProduct = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: ({ id, data }) => inventoryAPI.updateProduct(id, data),
      onSuccess: (_, variables) => {
        message.success('Product updated successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.detail(variables.id) });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to update product');
      }
    });
  };

  export const useDeleteProduct = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (id) => inventoryAPI.deleteProduct(id),
      onSuccess: () => {
        message.success('Product archived successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to archive product');
      }
    });
  };

  export const useRestoreProduct = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (id) => inventoryAPI.restoreProduct(id),
      onSuccess: () => {
        message.success('Product restored successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to restore product');
      }
    });
  };

  // ==================== EQUIPMENT ====================

 export const useEquipment = (filters = {}) => {
  return useQuery({
    queryKey: inventoryKeys.equipment.list(filters),
    queryFn: () => inventoryAPI.getEquipment(filters),
    select: (response) => {
      const data = response.data.data;
      // Normalize equipment data - ensure equipment_id exists
      const normalizedData = (data.data || []).map(item => ({
        ...item,
        equipment_id: item.equipment_id || item.id,
        id: item.id || item.equipment_id,
      }));
      return {
        data: normalizedData,
        total: data.total || 0,
        current_page: data.current_page || 1,
        last_page: data.last_page || 1,
        per_page: data.per_page || 15
      };
    },
    staleTime: 5 * 60 * 1000,
    keepPreviousData: true
  });
};

  export const useEquipmentStats = () => {
    return useQuery({
      queryKey: inventoryKeys.equipment.stats(),
      queryFn: () => inventoryAPI.getEquipmentStats(),
      select: (response) => response.data.data,
      staleTime: 5 * 60 * 1000,
    });
  };

  export const useEquipmentItem = (id) => {
    return useQuery({
      queryKey: inventoryKeys.equipment.detail(id),
      queryFn: () => inventoryAPI.getEquipmentItem(id),
      select: (response) => response.data.data,
      enabled: !!id,
      staleTime: 5 * 60 * 1000,
    });
  };

  export const useEquipmentHistory = (id) => {
    return useQuery({
      queryKey: inventoryKeys.equipment.history(id),
      queryFn: () => inventoryAPI.getEquipmentHistory(id),
      select: (response) => response.data.data,
      enabled: !!id,
      staleTime: 2 * 60 * 1000,
    });
  };

  export const useCreateEquipment = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (data) => inventoryAPI.createEquipment(data),
      onSuccess: () => {
        message.success('Equipment created successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to create equipment');
      }
    });
  };

  export const useUpdateEquipment = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: ({ id, data }) => inventoryAPI.updateEquipment(id, data),
      onSuccess: (_, variables) => {
        message.success('Equipment updated successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.detail(variables.id) });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to update equipment');
      }
    });
  };

  export const useDeleteEquipment = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (id) => inventoryAPI.deleteEquipment(id),
      onSuccess: () => {
        message.success('Equipment archived successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to archive equipment');
      }
    });
  };

  export const useRestoreEquipment = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (id) => inventoryAPI.restoreEquipment(id),
      onSuccess: () => {
        message.success('Equipment restored successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to restore equipment');
      }
    });
  };

  // ==================== INVENTORY MOVEMENTS ====================

  export const useInventoryMovements = (filters = {}) => {
    return useQuery({
      queryKey: inventoryKeys.movements.list(filters),
      queryFn: () => inventoryAPI.getMovements(filters),
      select: (response) => {
        const data = response?.data?.data;
        if (Array.isArray(data)) return data;
        if (data?.data && Array.isArray(data.data)) return data.data;
        return [];
      },
      staleTime: 2 * 60 * 1000,
    });
  };

  export const useRecordMovement = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (data) => inventoryAPI.recordMovement(data),
      onSuccess: () => {
        message.success('Movement recorded successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.movements.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to record movement');
      }
    });
  };

  // ==================== WASTE RECORDS ====================

  export const useWasteRecords = (filters = {}) => {
    return useQuery({
      queryKey: inventoryKeys.waste.list(filters),
      queryFn: () => inventoryAPI.getWasteRecords(filters),
      select: (response) => {
        const data = response?.data?.data;
        if (Array.isArray(data)) return data;
        if (data?.data && Array.isArray(data.data)) return data.data;
        return [];
      },
      staleTime: 2 * 60 * 1000,
    });
  };

  export const useRecordWaste = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (data) => inventoryAPI.recordWaste(data),
      onSuccess: () => {
        message.success('Waste recorded successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.waste.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.products.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to record waste');
      }
    });
  };

  // ==================== SUPPLIERS ====================

  export const useSuppliers = (filters = {}) => {
    return useQuery({
      queryKey: inventoryKeys.suppliers.list(filters),
      queryFn: () => inventoryAPI.getSuppliers(filters),
      select: (response) => {
        const data = response?.data?.data;
        if (Array.isArray(data)) return data;
        if (data?.data && Array.isArray(data.data)) return data.data;
        return [];
      },
      staleTime: 5 * 60 * 1000,
    });
  };

  export const useCreateSupplier = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (data) => inventoryAPI.createSupplier(data),
      onSuccess: () => {
        message.success('Supplier created successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.suppliers.all });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to create supplier');
      }
    });
  };

  // ==================== PURCHASE REQUESTS ====================

  export const usePurchaseRequests = (filters = {}) => {
    return useQuery({
      queryKey: inventoryKeys.purchaseRequests.list(filters),
      queryFn: () => api.get('/inventory/purchase-requests', { params: filters }),
      select: (response) => {
        const data = response?.data?.data;
        if (Array.isArray(data)) return data;
        if (data?.data && Array.isArray(data.data)) return data.data;
        return [];
      },
      staleTime: 2 * 60 * 1000,
    });
  };

  export const useCreatePurchaseRequest = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (data) => api.post('/inventory/purchase-requests', data),
      onSuccess: () => {
        message.success('Purchase request created successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.purchaseRequests.all });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to create purchase request');
      }
    });
  };

  export const useUpdatePurchaseRequest = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: ({ id, data }) => api.put(`/inventory/purchase-requests/${id}`, data),
      onSuccess: () => {
        message.success('Purchase request updated successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.purchaseRequests.all });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to update purchase request');
      }
    });
  };

  // ==================== EQUIPMENT RESERVATIONS ====================

  export const useEquipmentReservations = (filters = {}) => {
    return useQuery({
      queryKey: inventoryKeys.reservations.list(filters),
      queryFn: () => inventoryAPI.getEquipmentReservations(filters),
      select: (response) => {
        const data = response?.data?.data;
        if (Array.isArray(data)) return data;
        if (data?.data && Array.isArray(data.data)) return data.data;
        return [];
      },
      staleTime: 2 * 60 * 1000,
    });
  };

  export const useCreateEquipmentReservation = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: (data) => inventoryAPI.createEquipmentReservation(data),
      onSuccess: () => {
        message.success('Equipment reserved successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.reservations.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.stats() });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.dashboard.stats });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to reserve equipment');
      }
    });
  };

  export const useUpdateEquipmentReservation = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
      mutationFn: ({ id, data }) => api.put(`/equipment/reservations/${id}`, data),
      onSuccess: () => {
        message.success('Equipment reservation updated successfully');
        queryClient.invalidateQueries({ queryKey: inventoryKeys.reservations.all });
        queryClient.invalidateQueries({ queryKey: inventoryKeys.equipment.all });
      },
      onError: (error) => {
        message.error(error.response?.data?.message || 'Failed to update reservation');
      }
    });
  };

  // ==================== DASHBOARD STATS ====================

  export const useInventoryDashboardStats = () => {
    return useQuery({
      queryKey: inventoryKeys.dashboard.stats,
      queryFn: () => inventoryAPI.getDashboardStats(),
      select: (response) => response?.data?.data || {},
      staleTime: 3 * 60 * 1000,
      refetchInterval: 30000,
    });
  };

  // ==================== STOCK VALUE ====================

  export const useStockValue = () => {
    return useQuery({
      queryKey: ['stock-value'],
      queryFn: () => api.get('/inventory/stock-value'),
      select: (response) => response?.data?.data || { total_value: 0, by_category: [] },
      staleTime: 5 * 60 * 1000,
    });
  };

  // ==================== HISTORY ====================

  export const useItemHistory = (type, id) => {
    return useQuery({
      queryKey: inventoryKeys.history.item(type, id),
      queryFn: () => inventoryAPI.getItemHistory(type, id),
      select: (response) => {
        const data = response?.data?.data;
        if (Array.isArray(data)) return data;
        if (data?.data && Array.isArray(data.data)) return data.data;
        return [];
      },
      enabled: !!id,
      staleTime: 2 * 60 * 1000,
    });
  };

  // ==================== EQUIPMENT WARNINGS ====================

  export const useEquipmentWarnings = () => {
    return useQuery({
      queryKey: ['equipment-warnings'],
      queryFn: () => api.get('/inventory/equipment-warnings'),
      select: (response) => response?.data?.data || [],
      staleTime: 2 * 60 * 1000,
    });
  };

  // ==================== INVENTORY SUMMARY ====================

  export const useInventorySummary = () => {
    return useQuery({
      queryKey: ['inventory-summary'],
      queryFn: () => api.get('/inventory/summary'),
      select: (response) => response?.data?.data || {},
      staleTime: 5 * 60 * 1000,
    });
  };