
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import { orderAPI } from '../services/api';

export const orderKeys = {
    all: ['orders'],
    lists: () => [...orderKeys.all, 'list'],
    list: (filters) => [...orderKeys.lists(), { filters }],
    details: () => [...orderKeys.all, 'detail'],
    detail: (id) => [...orderKeys.details(), id],
    kitchen: () => [...orderKeys.all, 'kitchen'],
    delivery: () => [...orderKeys.all, 'delivery'],
    statistics: () => [...orderKeys.all, 'statistics'],
    ingredients: (orderId) => [...orderKeys.all, 'ingredients', orderId],
    shoppingList: () => [...orderKeys.all, 'shopping-list']
};

// ==================== QUERIES ====================

export const useOrders = (filters = {}) => {
    return useQuery({
        queryKey: orderKeys.list(filters),
        queryFn: () => orderAPI.getOrders(filters),
        select: (response) => {
            console.log('Orders API Full Response:', response.data);
            
            // Extract data from nested structure
            const responseData = response.data;
            
            // Handle different response structures
            if (responseData && responseData.success) {
                const data = responseData.data;
                
                // Check if data is an array or has nested structure
                let ordersArray = [];
                let total = 0;
                let currentPage = 1;
                let lastPage = 1;
                let perPage = 15;
                
                if (Array.isArray(data)) {
                    ordersArray = data;
                    total = data.length;
                } else if (data && Array.isArray(data.data)) {
                    ordersArray = data.data;
                    total = data.total || data.data.length;
                    currentPage = data.current_page || 1;
                    lastPage = data.last_page || 1;
                    perPage = data.per_page || 15;
                } else if (data && typeof data === 'object') {
                    // Try to find array in the object
                    ordersArray = data.data || data.orders || [];
                    total = data.total || ordersArray.length;
                    currentPage = data.current_page || 1;
                    lastPage = data.last_page || 1;
                    perPage = data.per_page || 15;
                }
                
                console.log('Parsed Orders:', ordersArray);
                console.log('Total Orders:', total);
                
                return {
                    data: ordersArray,
                    total: total,
                    current_page: currentPage,
                    last_page: lastPage,
                    per_page: perPage
                };
            }
            
            // Fallback: try to extract data directly
            const fallbackData = responseData?.data?.data || responseData?.data || [];
            console.log('Fallback Orders:', fallbackData);
            
            return {
                data: Array.isArray(fallbackData) ? fallbackData : [],
                total: Array.isArray(fallbackData) ? fallbackData.length : 0,
                current_page: 1,
                last_page: 1,
                per_page: 15
            };
        },
        staleTime: 5 * 60 * 1000,
        keepPreviousData: true,
        refetchOnWindowFocus: true,
        refetchOnMount: true
    });
};

export const useOrder = (id) => {
    return useQuery({
        queryKey: orderKeys.detail(id),
        queryFn: () => orderAPI.getOrder(id),
        select: (response) => {
            console.log('Order Detail Response:', response.data);
            return response.data?.data?.data || response.data?.data || response.data;
        },
        enabled: !!id,
        staleTime: 5 * 60 * 1000
    });
};

export const useKitchenOrders = () => {
    return useQuery({
        queryKey: orderKeys.kitchen(),
        queryFn: () => orderAPI.getKitchenOrders(),
        select: (response) => {
            console.log('Kitchen Orders Response:', response.data);
            const data = response.data?.data?.data || response.data?.data || [];
            return Array.isArray(data) ? data : [];
        },
        staleTime: 2 * 60 * 1000
    });
};

export const useDeliveryOrders = () => {
    return useQuery({
        queryKey: orderKeys.delivery(),
        queryFn: () => orderAPI.getDeliveryOrders(),
        select: (response) => {
            console.log('Delivery Orders Response:', response.data);
            const data = response.data?.data?.data || response.data?.data || [];
            return Array.isArray(data) ? data : [];
        },
        staleTime: 2 * 60 * 1000
    });
};

export const useOrderStatistics = () => {
    return useQuery({
        queryKey: orderKeys.statistics(),
        queryFn: () => orderAPI.getStatistics(),
        select: (response) => {
            console.log('Statistics Response:', response.data);
            const stats = response.data?.data?.data || response.data?.data || response.data;
            return {
                total_orders: stats?.total_orders || 0,
                total_revenue: stats?.total_revenue || 0,
                completed_orders: stats?.completed_orders || 0,
                pending_orders: stats?.pending_orders || 0,
                preparing_orders: stats?.preparing_orders || 0,
                ready_orders: stats?.ready_orders || 0,
                ongoing_orders: stats?.ongoing_orders || 0,
                cancelled_orders: stats?.cancelled_orders || 0,
                pending_purchases: stats?.pending_purchases || 0,
                kitchen_orders: stats?.kitchen_orders || 0,
                delivery_orders: stats?.delivery_orders || 0,
                ingredients_computed: stats?.ingredients_computed || 0
            };
        },
        staleTime: 2 * 60 * 1000,
        refetchInterval: 30000
    });
};

export const useIngredientsComputed = (orderId) => {
    return useQuery({
        queryKey: orderKeys.ingredients(orderId),
        queryFn: () => orderAPI.getIngredientsComputed(orderId),
        select: (response) => {
            console.log('Ingredients Response:', response.data);
            return response.data?.data?.data || response.data?.data || [];
        },
        enabled: !!orderId,
        staleTime: 5 * 60 * 1000
    });
};

// ==================== MUTATIONS ====================

export const useCreateOrder = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (data) => orderAPI.createOrder(data),
        onSuccess: () => {
            message.success('Order created successfully');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.statistics() });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to create order');
        }
    });
};

export const useUpdateOrder = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: ({ id, data }) => orderAPI.updateOrder(id, data),
        onSuccess: (response, variables) => {
            message.success('Order updated successfully');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(variables.id) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to update order');
        }
    });
};

export const useDeleteOrder = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (id) => orderAPI.deleteOrder(id),
        onSuccess: () => {
            message.success('Order deleted successfully');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.statistics() });
            queryClient.invalidateQueries({ queryKey: orderKeys.kitchen() });
            queryClient.invalidateQueries({ queryKey: orderKeys.delivery() });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to delete order');
        }
    });
};

export const useUpdateOrderStatus = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: ({ id, data }) => orderAPI.updateStatus(id, data),
        onSuccess: (response, variables) => {
            message.success(`Order status updated`);
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.statistics() });
            queryClient.invalidateQueries({ queryKey: orderKeys.kitchen() });
            queryClient.invalidateQueries({ queryKey: orderKeys.delivery() });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to update status');
        }
    });
};

export const useAddToKitchen = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (id) => orderAPI.addToKitchen(id),
        onSuccess: (response, id) => {
            message.success('Order added to kitchen preparation');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.kitchen() });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(id) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to add to kitchen');
        }
    });
};

export const useRemoveFromKitchen = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (id) => orderAPI.removeFromKitchen(id),
        onSuccess: (response, id) => {
            message.info('Order removed from kitchen preparation');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.kitchen() });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(id) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to remove from kitchen');
        }
    });
};

export const useUpdateKitchenTask = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: ({ orderId, data }) => orderAPI.updateKitchenTask(orderId, data),
        onSuccess: (response, variables) => {
            message.success('Kitchen task updated');
            queryClient.invalidateQueries({ queryKey: orderKeys.kitchen() });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(variables.orderId) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to update kitchen task');
        }
    });
};

export const useAddToDelivery = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (id) => orderAPI.addToDelivery(id),
        onSuccess: (response, id) => {
            message.success('Order added to delivery preparation');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.delivery() });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(id) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to add to delivery');
        }
    });
};

export const useRemoveFromDelivery = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (id) => orderAPI.removeFromDelivery(id),
        onSuccess: (response, id) => {
            message.info('Order removed from delivery preparation');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: orderKeys.delivery() });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(id) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to remove from delivery');
        }
    });
};

export const useUpdateDeliveryItem = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: ({ orderId, data }) => orderAPI.updateDeliveryItem(orderId, data),
        onSuccess: (response, variables) => {
            message.success('Delivery item updated');
            queryClient.invalidateQueries({ queryKey: orderKeys.delivery() });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(variables.orderId) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to update delivery item');
        }
    });
};

export const useComputeIngredients = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (id) => orderAPI.computeIngredients(id),
        onSuccess: (response, id) => {
            message.success('Ingredients computed successfully');
            queryClient.invalidateQueries({ queryKey: orderKeys.ingredients(id) });
            queryClient.invalidateQueries({ queryKey: orderKeys.detail(id) });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to compute ingredients');
        }
    });
};

export const useAddToShoppingList = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: ({ orderId, data }) => orderAPI.addToShoppingList(orderId, data),
        onSuccess: () => {
            message.success('Items added to shopping list');
            queryClient.invalidateQueries({ queryKey: orderKeys.shoppingList() });
            queryClient.invalidateQueries({ queryKey: orderKeys.statistics() });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to add to shopping list');
        }
    });
};

export const useCreateOrderFromBooking = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (bookingId) => orderAPI.createFromBooking(bookingId),
        onSuccess: () => {
            message.success('Order created from booking');
            queryClient.invalidateQueries({ queryKey: orderKeys.all });
            queryClient.invalidateQueries({ queryKey: ['bookings'] });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to create order from booking');
        }
    });
};
