import React from 'react'
import { Helmet } from 'react-helmet-async'
import StatsCards from '../components/dashboard/StatsCards'
import RecentSales from '../components/dashboard/RecentSales'
import ProductsLowStock from '../components/dashboard/ProductsLowStock'
import SalesChart from '../components/dashboard/SalesChart'
import { useApi } from '../hooks/useApi'
import './Dashboard.css'

const Dashboard = () => {
  const { data: dashboardData, isLoading } = useApi({
    url: '/api/dashboard',
    key: ['dashboard']
  })

  if (isLoading) {
    return (
      <div className="loading-container">
        <div className="loading-spinner"></div>
      </div>
    )
  }

  return (
    <>
      <Helmet>
        <title>Dashboard - Biznexa</title>
      </Helmet>
      
      <div className="dashboard">
        <div className="dashboard-header">
          <h1 className="page-title">Dashboard</h1>
          <div className="date-filter">
            <select className="filter-select">
              <option>Hoje</option>
              <option>Esta Semana</option>
              <option>Este Mês</option>
              <option>Personalizado</option>
            </select>
          </div>
        </div>

        <p className="page-subtitle">
          Bem-vindo de volta! Aqui está o resumo do seu negócio.
        </p>

        <StatsCards data={dashboardData?.stats || {}} />

        <div className="dashboard-charts">
          <div className="chart-container">
            <SalesChart />
          </div>
        </div>

        <div className="dashboard-tables">
          <div className="table-section">
            <RecentSales />
          </div>
          <div className="table-section">
            <ProductsLowStock />
          </div>
        </div>
      </div>
    </>
  )
}

export default Dashboard
