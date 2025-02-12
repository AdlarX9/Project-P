import { useInfiniteQuery, useMutation } from '@tanstack/react-query'
import axios from 'axios'
import { useSelector } from 'react-redux'
import { getToken } from '../reduxStore/selectors'

const axiosSendMessage = async (token, friendUsername, message) => {
	return axios
		.post(
			process.env.REACT_APP_API_URL + '/api/friends/send_message/' + friendUsername,
			{ message },
			{ headers: { Authorization: token } }
		)
		.then(response => response.data)
		.catch(err => {
			throw new Error(err.message)
		})
}

export const useSendMessage = () => {
	const token = useSelector(getToken)
	const mutation = useMutation({
		mutationFn: (friendUsername, message) => axiosSendMessage(token, friendUsername, message)
	})

	const sendMessage = (friendUsername, message) => {
		mutation.mutate(friendUsername, message)
	}

	return { sendMessage, ...mutation }
}

const axiosDeleteMessage = (token, messageId) => {
	return axios
		.delete(process.env.REACT_APP_API_URL + '/api/friends/delete_message/' + messageId, {
			headers: { Authorization: token }
		})
		.then(response => response.data)
		.catch(err => {
			throw new Error(err.message)
		})
}

export const useDeleteMessage = () => {
	const token = useSelector(getToken)

	const mutation = useMutation({
		mutationFn: messageId => axiosDeleteMessage(token, messageId)
	})

	const deleteMessage = messageId => {
		mutation.mutate(messageId)
	}

	return { deleteMessage, ...mutation }
}

const axiosGetConversation = async (token, friendUsername, page) => {
	return axios
		.get(process.env.REACT_APP_API_URL + '/api/friends/get_conversation/' + friendUsername, {
			headers: { Authorization: token },
			data: { page, limit: 10 }
		})
		.then(response => response.data)
		.catch(err => {
			throw new Error(err.message)
		})
}

export const useGetConversation = friendUsername => {
	const token = useSelector(getToken)

	const infiniteQuery = useInfiniteQuery({
		queryKey: ['searchUsers'],
		queryFn: async ({ pageParam = 1 }) =>
			axiosGetConversation(token, friendUsername, pageParam),
		initialPageParam: 1,
		getNextPageParam: (lastPage, allPages) => {
			return lastPage.length === 10 ? allPages.length + 1 : undefined
		},
		retry: 0
	})

	return infiniteQuery
}
