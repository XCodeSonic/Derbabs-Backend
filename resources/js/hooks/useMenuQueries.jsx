// src/hooks/useMenuQueries.js

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import {
  categoryAPI,
  ingredientAPI,
  menuAPI,
  packageAPI,
  promotionAPI,
  recipeAPI,
  statisticsAPI,
} from '../services/api';

export const menuKeys = {
  root: ['menu-management'],
  menus: (params = {}) => {
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([_, value]) => value !== undefined && value !== null)
    );
    return ['menu-management', 'menus', cleanParams];
  },
  menu: (id) => ['menu-management', 'menu', id],
  categories: (params = {}) => {
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([_, value]) => value !== undefined && value !== null)
    );
    return ['menu-management', 'categories', cleanParams];
  },
  packages: (params = {}) => {
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([_, value]) => value !== undefined && value !== null)
    );
    return ['menu-management', 'packages', cleanParams];
  },
  package: (id) => ['menu-management', 'package', id],
  promotions: (params = {}) => {
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([_, value]) => value !== undefined && value !== null)
    );
    return ['menu-management', 'promotions', cleanParams];
  },
  ingredients: (params = {}) => {
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([_, value]) => value !== undefined && value !== null)
    );
    return ['menu-management', 'ingredients', cleanParams];
  },
  recipes: ['menu-management', 'recipes'],
  recipe: (id) => ['menu-management', 'recipe', id],
  statistics: ['menu-management', 'statistics'],
};

const asArray = (value) => (Array.isArray(value) ? value : []);

const asBoolean = (value, fallback = false) => {
  if (value === undefined || value === null) return fallback;
  return value === true || value === 1 || value === '1' || value === 'true';
};

const payloadOf = (response) => response?.data?.data ?? response?.data ?? null;

const paginated = (response, mapper) => {
  const payload = payloadOf(response);

  if (Array.isArray(payload)) {
    return {
      data: payload.map(mapper),
      total: payload.length,
      current_page: 1,
      last_page: 1,
      per_page: payload.length || 15,
    };
  }

  if (payload && Array.isArray(payload.data)) {
    return {
      ...payload,
      data: payload.data.map(mapper),
      total: Number(payload.total ?? payload.data.length),
      current_page: Number(payload.current_page ?? 1),
      last_page: Number(payload.last_page ?? 1),
      per_page: Number(payload.per_page ?? 15),
    };
  }

  return { data: [], total: 0, current_page: 1, last_page: 1, per_page: 15 };
};

const list = (response, mapper) => {
  const result = paginated(response, mapper);
  return result.data;
};

export const errorMessage = (error, fallback = 'Request failed. Please try again.') => {
  const errors = error?.response?.data?.errors;
  const firstValidationMessage = errors
    ? Object.values(errors).flat().find(Boolean)
    : null;

  return error?.response?.data?.message
    || firstValidationMessage
    || error?.message
    || fallback;
};

const mapRecipeIngredient = (row = {}) => ({
  id: row.recipe_ingredient_id ?? row.id,
  recipe_ingredient_id: row.recipe_ingredient_id ?? row.id,
  ingredient_id: row.ingredient_id,
  quantity_per_pax: Number(row.quantity_per_pax ?? 0),
  unit: row.unit || row.ingredient?.unit || 'kg',
  ingredient: row.ingredient || null,
  name: row.ingredient?.name || '',
  unit_cost: Number(row.ingredient?.unit_cost ?? 0),
});

export const mapMenuItem = (item = {}) => ({
  ...item,
  id: item.menu_item_id ?? item.id,
  menu_item_id: item.menu_item_id ?? item.id,
  category_id: item.category_id,
  category: item.category?.name ?? item.category_name ?? 'Uncategorized',
  category_record: item.category ?? null,
  price: Number(item.price ?? 0),
  cost_to_make: Number(item.cost_to_make ?? 0),
  prep_time_minutes: Number(item.prep_time_minutes ?? 0),
  serving_size: Number(item.serving_size ?? 1),
  image: item.image_url ?? item.image ?? null,
  image_url: item.image_url ?? item.image ?? null,
  is_available: asBoolean(item.is_available, true),
  is_popular: asBoolean(item.is_popular),
  is_vegetarian: asBoolean(item.is_vegetarian),
  is_vegan: asBoolean(item.is_vegan),
  is_gluten_free: asBoolean(item.is_gluten_free),
  is_halal: asBoolean(item.is_halal),
  recipe_ingredients: asArray(item.recipe_ingredients ?? item.recipeIngredients)
    .map(mapRecipeIngredient),
});

export const mapCategory = (category = {}) => ({
  ...category,
  id: category.category_id ?? category.id,
  category_id: category.category_id ?? category.id,
  display_order: Number(category.display_order ?? category.sort_order ?? 0),
  is_active: asBoolean(category.is_active, true),
  menu_items_count: Number(category.menu_items_count ?? 0),
});

const mapPackageItem = (row = {}) => {
  const menuItem = row.menu_item ?? row.menuItem ?? row;

  return {
    ...row,
    id: menuItem.menu_item_id ?? menuItem.id,
    menu_item_id: menuItem.menu_item_id ?? menuItem.id,
    name: menuItem.name ?? '',
    price: Number(menuItem.price ?? 0),
    image: menuItem.image_url ?? null,
    quantity_per_pax: Number(row.quantity_per_pax ?? row.quantity ?? 1),
    quantity: Number(row.quantity_per_pax ?? row.quantity ?? 1),
    is_optional: asBoolean(row.is_optional),
    is_replaceable: asBoolean(row.is_replaceable),
    additional_cost: Number(row.additional_cost ?? 0),
    menu_item: menuItem,
  };
};

export const mapPackage = (pkg = {}) => {
  const items = asArray(pkg.items ?? pkg.menu_items ?? pkg.menuItems).map(mapPackageItem);
  const menuItems = asArray(pkg.menu_items ?? pkg.items ?? []).map(mapPackageItem);

  return {
    ...pkg,
    id: pkg.package_id ?? pkg.id,
    package_id: pkg.package_id ?? pkg.id,
    base_price_per_pax: Number(pkg.base_price_per_pax ?? 0),
    price_per_additional_pax: Number(pkg.price_per_additional_pax ?? 0),
    min_pax: Number(pkg.min_pax ?? 1),
    max_pax: Number(pkg.max_pax ?? 1),
    default_duration_hours: Number(pkg.default_duration_hours ?? 4),
    sort_order: Number(pkg.sort_order ?? 0),
    is_active: asBoolean(pkg.is_active, true),
    is_featured: asBoolean(pkg.is_featured),
    inclusions: asArray(pkg.inclusions),
    exclusions: asArray(pkg.exclusions),
    items_count: Number(pkg.items_count ?? pkg.menu_items?.length ?? 0),
    items,
    menu_items: menuItems,
  };
};

export const mapPromotion = (promotion = {}) => ({
  ...promotion,
  id: promotion.promotion_id ?? promotion.id,
  promotion_id: promotion.promotion_id ?? promotion.id,
  discount_value: Number(promotion.discount_value ?? 0),
  is_active: asBoolean(promotion.is_active, true),
});

export const mapIngredient = (ingredient = {}) => ({
  ...ingredient,
  id: ingredient.ingredient_id ?? ingredient.id,
  ingredient_id: ingredient.ingredient_id ?? ingredient.id,
  unit_cost: Number(ingredient.unit_cost ?? 0),
  is_active: asBoolean(ingredient.is_active, true),
});

const invalidateMenuModule = (queryClient) => {
  queryClient.invalidateQueries({ queryKey: menuKeys.root });
};

const useMutationWithMessage = ({ mutationFn, successText, onSuccess }) => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (response, variables) => {
      message.success(response?.data?.message || response?.message || successText);
      invalidateMenuModule(queryClient);
      onSuccess?.(response, variables, queryClient);
    },
    onError: (error) => message.error(errorMessage(error)),
  });
};

// CRITICAL: Cache configuration - Data loads ONLY ONCE
const queryOptions = {
  staleTime: Infinity, // Data never becomes stale - prevents refetching
  gcTime: Infinity, // Data never garbage collected - stays in cache forever (was cacheTime)
  refetchOnWindowFocus: false, // Don't refetch on window focus
  refetchOnMount: false, // Don't refetch on component mount
  refetchOnReconnect: false, // Don't refetch on reconnect
  refetchInterval: false, // No interval refetching
  keepPreviousData: true, // Keep previous data while fetching
  retry: 1,
  retryDelay: 1000,
  initialData: undefined,
  // Prevent any automatic refetching
  refetchOnMount: 'always', // Use cached data if available
};

export const useMenuItems = (params = {}) => useQuery({
  queryKey: menuKeys.menus(params),
  queryFn: async () => paginated(await menuAPI.getMenuItems(params), mapMenuItem),
  ...queryOptions,
  enabled: true,
});

export const useMenuItem = (id) => useQuery({
  queryKey: menuKeys.menu(id),
  queryFn: async () => mapMenuItem(payloadOf(await menuAPI.getMenuItem(id))),
  enabled: Boolean(id),
  ...queryOptions,
});

export const useCreateMenuItem = () => useMutationWithMessage({
  mutationFn: async (data) => (await menuAPI.createMenuItem(data)).data,
  successText: 'Menu item created successfully',
});

export const useUpdateMenuItem = () => useMutationWithMessage({
  mutationFn: async ({ id, data }) => (await menuAPI.updateMenuItem({ id, data })).data,
  successText: 'Menu item updated successfully',
});

export const useDeleteMenuItem = () => useMutationWithMessage({
  mutationFn: async (id) => (await menuAPI.deleteMenuItem(id)).data,
  successText: 'Menu item deleted successfully',
});

export const useToggleMenuItemAvailability = () => useMutationWithMessage({
  mutationFn: async (id) => (await menuAPI.toggleAvailability(id)).data,
  successText: 'Availability updated successfully',
});

export const useToggleMenuItemFeatured = () => useMutationWithMessage({
  mutationFn: async (id) => (await menuAPI.toggleFeatured(id)).data,
  successText: 'Featured status updated successfully',
});

export const useCategories = (params = {}) => useQuery({
  queryKey: menuKeys.categories(params),
  queryFn: async () => paginated(await categoryAPI.getCategories(params), mapCategory),
  ...queryOptions,
});

export const useCreateCategory = () => useMutationWithMessage({
  mutationFn: async (data) => (await categoryAPI.createCategory(data)).data,
  successText: 'Category created successfully',
});

export const useUpdateCategory = () => useMutationWithMessage({
  mutationFn: async ({ id, data }) => (await categoryAPI.updateCategory({ id, data })).data,
  successText: 'Category updated successfully',
});

export const useDeleteCategory = () => useMutationWithMessage({
  mutationFn: async (id) => (await categoryAPI.deleteCategory(id)).data,
  successText: 'Category deleted successfully',
});

export const usePackages = (params = {}) => useQuery({
  queryKey: menuKeys.packages(params),
  queryFn: async () => paginated(await packageAPI.getPackages(params), mapPackage),
  ...queryOptions,
});

export const usePackage = (id) => useQuery({
  queryKey: menuKeys.package(id),
  queryFn: async () => mapPackage(payloadOf(await packageAPI.getPackage(id))),
  enabled: Boolean(id),
  ...queryOptions,
});

export const useCreatePackage = () => useMutationWithMessage({
  mutationFn: async (data) => (await packageAPI.createPackage(data)).data,
  successText: 'Package created successfully',
});

export const useUpdatePackage = () => useMutationWithMessage({
  mutationFn: async ({ id, data }) => (await packageAPI.updatePackage({ id, data })).data,
  successText: 'Package updated successfully',
});

export const useDeletePackage = () => useMutationWithMessage({
  mutationFn: async (id) => (await packageAPI.deletePackage(id)).data,
  successText: 'Package deleted successfully',
});

export const usePromotions = (params = {}) => useQuery({
  queryKey: menuKeys.promotions(params),
  queryFn: async () => paginated(await promotionAPI.getPromotions(params), mapPromotion),
  ...queryOptions,
});

export const useCreatePromotion = () => useMutationWithMessage({
  mutationFn: async (data) => (await promotionAPI.createPromotion(data)).data,
  successText: 'Promotion created successfully',
});

export const useUpdatePromotion = () => useMutationWithMessage({
  mutationFn: async ({ id, data }) => (await promotionAPI.updatePromotion({ id, data })).data,
  successText: 'Promotion updated successfully',
});

export const useDeletePromotion = () => useMutationWithMessage({
  mutationFn: async (id) => (await promotionAPI.deletePromotion(id)).data,
  successText: 'Promotion deleted successfully',
});

export const useIngredients = (params = {}) => useQuery({
  queryKey: menuKeys.ingredients(params),
  queryFn: async () => paginated(await ingredientAPI.getIngredients(params), mapIngredient),
  ...queryOptions,
});

export const useCreateIngredient = () => useMutationWithMessage({
  mutationFn: async (data) => (await ingredientAPI.createIngredient(data)).data,
  successText: 'Ingredient created successfully',
});

export const useUpdateIngredient = () => useMutationWithMessage({
  mutationFn: async ({ id, data }) => (await ingredientAPI.updateIngredient({ id, data })).data,
  successText: 'Ingredient updated successfully',
});

export const useDeleteIngredient = () => useMutationWithMessage({
  mutationFn: async (id) => (await ingredientAPI.deleteIngredient(id)).data,
  successText: 'Ingredient deleted successfully',
});

export const useRecipes = () => useQuery({
  queryKey: menuKeys.recipes,
  queryFn: async () => list(await recipeAPI.getRecipes(), mapMenuItem),
  ...queryOptions,
});

export const useRecipe = (menuItemId) => useQuery({
  queryKey: menuKeys.recipe(menuItemId),
  queryFn: async () => mapMenuItem(payloadOf(await recipeAPI.getRecipe(menuItemId))),
  enabled: Boolean(menuItemId),
  ...queryOptions,
});

export const useSaveRecipe = () => useMutationWithMessage({
  mutationFn: async (data) => (await recipeAPI.saveRecipe(data)).data,
  successText: 'Recipe saved successfully',
});

export const useDeleteRecipe = () => useMutationWithMessage({
  mutationFn: async (id) => (await recipeAPI.deleteRecipe(id)).data,
  successText: 'Recipe deleted successfully',
});

export const useMenuStatistics = () => useQuery({
  queryKey: menuKeys.statistics,
  queryFn: async () => payloadOf(await statisticsAPI.getMenuStatistics()) || {},
  ...queryOptions,
  retry: false,
});