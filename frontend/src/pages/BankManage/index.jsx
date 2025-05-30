import './style.css'
import Back from '@components/Back'
import Background from '@components/Background'
import { ManagePanel } from '@features/bank'

const BankManage = () => {
	return (
		<main className='bg-bank-red bg center-children oh'>
			<div className='back-wrapper'>
				<Back />
			</div>
			<Background theme='red' img='dollarSign' />
			<ManagePanel />
		</main>
	)
}

export default BankManage
