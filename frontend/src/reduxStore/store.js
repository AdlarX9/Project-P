import { combineReducers, configureStore } from '@reduxjs/toolkit'
import userReducer from './userSlice'
import friendsReducer from './friendsSlice'
import notificationsReducer from './notificationsSlice'
import matchmakingReducer from './matchmakingSlice'
import settingsReducer from './settingsSlice'

const defaultState = {
	user: {},
	friends: [],
	notifications: [],
	matchmaking: { state: 'nothing', id: null },
	settings: {
		communicationPrefer: 'call' // call | text
	},
	messages: []
}

export const saveStateUser = reduxState => {
	try {
		const serializedState = JSON.stringify(reduxState)
		localStorage.removeItem('reduxStateUser')
		localStorage.setItem('reduxStateUser', serializedState)
	} catch (e) {
		console.error('Could not save state', e)
	}
}

export const saveStateFriends = reduxState => {
	try {
		const serializedState = JSON.stringify(reduxState)
		localStorage.removeItem('reduxStateFriends')
		localStorage.setItem('reduxStateFriends', serializedState)
	} catch (e) {
		console.error('Could not save state', e)
	}
}

export const saveStateNotifications = reduxState => {
	try {
		const serializedState = JSON.stringify(reduxState)
		localStorage.removeItem('reduxStateNotifications')
		localStorage.setItem('reduxStateNotifications', serializedState)
	} catch (e) {
		console.error('Could not save state', e)
	}
}

export const saveStateSettings = reduxState => {
	try {
		const serializedState = JSON.stringify(reduxState)
		localStorage.removeItem('reduxStateSettings')
		localStorage.setItem('reduxStateSettings', serializedState)
	} catch (e) {
		console.error('Could not save state', e)
	}
}

const loadState = () => {
	try {
		const user = localStorage.getItem('reduxStateUser')
		const friends = localStorage.getItem('reduxStateFriends')
		const notifications = localStorage.getItem('reduxStateNotifications')
		const settings = localStorage.getItem('reduxStateSettings')
		const serializedState = {
			user: JSON.parse(user),
			friends: JSON.parse(friends),
			notifications: JSON.parse(notifications),
			matchmaking: 'nothing',
			settings: JSON.parse(settings)
		}
		if (serializedState?.friends === null || serializedState?.user === null) {
			return defaultState
		}
		return serializedState
	} catch (e) {
		return defaultState
	}
}

let state = loadState()

export const store = configureStore({
	preloadedState: state,
	reducer: combineReducers({
		user: userReducer,
		friends: friendsReducer,
		notifications: notificationsReducer,
		matchmaking: matchmakingReducer,
		settings: settingsReducer
	}),
	devTools: true
})
