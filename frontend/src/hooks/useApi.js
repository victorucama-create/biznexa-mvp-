import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '../services/api'
import toast from 'react-hot-toast'

export const useApi = (options = {}) => {
  const {
    url,
    method = 'GET',
    data = null,
    params = {},
    key = [],
    enabled = true,
    onSuccess,
    onError,
    ...queryOptions
  } = options

  return useQuery({
    queryKey: key,
    queryFn: async () => {
      try {
        const response = await api({
          url,
          method,
          data,
          params
        })
        return response.data
      } catch (error) {
        throw error.response?.data || error
      }
    },
    enabled,
    onSuccess,
    onError: (error) => {
      toast.error(error.message || 'Ocorreu um erro')
      if (onError) onError(error)
    },
    ...queryOptions
  })
}

export const useApiMutation = (options = {}) => {
  const queryClient = useQueryClient()
  const {
    url,
    method = 'POST',
    invalidateQueries = [],
    onSuccess,
    onError,
    ...mutationOptions
  } = options

  return useMutation({
    mutationFn: async (data) => {
      try {
        const response = await api({
          url,
          method,
          data
        })
        return response.data
      } catch (error) {
        throw error.response?.data || error
      }
    },
    onSuccess: (data, variables, context) => {
      // Invalidate related queries
      invalidateQueries.forEach(queryKey => {
        queryClient.invalidateQueries({ queryKey })
      })
      
      toast.success(data.message || 'Operação realizada com sucesso!')
      if (onSuccess) onSuccess(data, variables, context)
    },
    onError: (error) => {
      toast.error(error.message || 'Ocorreu um erro')
      if (onError) onError(error)
    },
    ...mutationOptions
  })
}
