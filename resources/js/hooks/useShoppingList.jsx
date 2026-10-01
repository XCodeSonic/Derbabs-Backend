// src/hooks/useShoppingList.js
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import { orderAPI } from '../services/api';

export const shoppingListKeys = {
    all: ['shopping-list'],
    lists: () => [...shoppingListKeys.all, 'list'],
    list: (filters) => [...shoppingListKeys.lists(), { filters }],
};

export const useShoppingList = (filters = {}) => {
    return useQuery({
        queryKey: shoppingListKeys.list(filters),
        queryFn: () => orderAPI.getShoppingList(filters),
        select: (response) => response.data.data,
        staleTime: 2 * 60 * 1000,
    });
};

export const useMarkShoppingItemPurchased = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (itemId) => orderAPI.markShoppingItemPurchased(itemId),
        onSuccess: () => {
            message.success('Item marked as purchased');
            queryClient.invalidateQueries({ queryKey: shoppingListKeys.all });
            queryClient.invalidateQueries({ queryKey: ['orders', 'statistics'] });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to mark item as purchased');
        }
    });
};

export const useDeleteShoppingItem = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (itemId) => orderAPI.deleteShoppingItem(itemId),
        onSuccess: () => {
            message.success('Item removed from shopping list');
            queryClient.invalidateQueries({ queryKey: shoppingListKeys.all });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to remove item');
        }
    });
};

export const useBulkMarkPurchased = () => {
    const queryClient = useQueryClient();
    
    return useMutation({
        mutationFn: (itemIds) => orderAPI.bulkMarkPurchased(itemIds),
        onSuccess: (response) => {
            message.success(response.data?.message || 'Items marked as purchased');
            queryClient.invalidateQueries({ queryKey: shoppingListKeys.all });
            queryClient.invalidateQueries({ queryKey: ['orders', 'statistics'] });
        },
        onError: (error) => {
            message.error(error.response?.data?.message || 'Failed to mark items as purchased');
        }
    });
};

export const usePendingPurchasesCount = () => {
    return useQuery({
        queryKey: ['shopping-list', 'pending-count'],
        queryFn: () => orderAPI.getPendingPurchasesCount(),
        select: (response) => response.data.data.pending_count,
        staleTime: 1 * 60 * 1000,
        refetchInterval: 30000
    });
};