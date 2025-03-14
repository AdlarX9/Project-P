import { createSlice } from '@reduxjs/toolkit'
import { saveStateNotifications } from '@redux/store'

const notificationsSlice = createSlice({
	name: 'notifications',
	initialState: [],
	reducers: {
		logNotifications: (state, action) => {
			if (Array.isArray(action.payload)) {
				const notifications = action.payload
				saveStateNotifications(notifications)
				return notifications
			} else {
				return state
			}
		},

		receiveNotification: (state, action) => {
			const notifications = [
				...state.filter(notif => notif.id !== action.payload.id),
				action.payload
			]

			if (notifications.length === state.length || !action.payload?.id) {
				return state
			} else {
				saveStateNotifications(notifications)
				return notifications
			}
		},

		deleteNotification: (state, action) => {
			const updatedState = state.filter(notification => notification.id !== action.payload.id)
			saveStateNotifications(updatedState)
			return updatedState
		},

		logNotificationsOut: () => {
			const state = []
			saveStateNotifications(state)
			return state
		}
	}
})

export const { logNotifications, receiveNotification, deleteNotification, logNotificationsOut } =
	notificationsSlice.actions
export default notificationsSlice.reducer
