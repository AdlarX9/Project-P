import { useDispatch } from 'react-redux'
import { useLocation, useNavigate } from 'react-router-dom'
import { useEffect } from 'react'
import { logPersoInf } from '../reduxStore/userSlice'
import { useLogged, useLogout, useRenewToken } from '../hooks/userHooks'
import { reduxLogFriends } from '../reduxStore/friendsSlice'

const Check = () => {
	const dispatch = useDispatch()
	const navigate = useNavigate()
	const { pathname } = useLocation()
	const { isLogged, isLoading, data } = useLogged()
	const { renewToken } = useRenewToken()
	const logout = useLogout()

	// Vérifie si l'utilisateur est connecté et tente de renouveler le token
	useEffect(() => {
		if (!isLogged && pathname !== '/signup' && pathname !== '/login') {
			console.log('Tentative de renouvellement du token...')
			renewToken().then(success => {
				if (!success) {
					navigate('/login')
					logout()
				}
			})
		}
	}, [isLogged, pathname, navigate])

	useEffect(() => {
		if (!isLoading && data?.data?.id >= 0) {
			dispatch(logPersoInf(data.data))
			dispatch(reduxLogFriends(data.data))
		}
	}, [data, dispatch, navigate])

	return null
}

export default Check
