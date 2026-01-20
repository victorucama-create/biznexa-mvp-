import React, { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Header from './Header'
import Sidebar from './Sidebar'
import Footer from './Footer'
import '../../styles/components/layout.css'

const Layout = () => {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  const toggleSidebar = () => {
    setSidebarOpen(!sidebarOpen)
  }

  const closeSidebar = () => {
    setSidebarOpen(false)
  }

  return (
    <div className="app-layout">
      <Header toggleSidebar={toggleSidebar} />
      <div className="layout-content">
        <Sidebar isOpen={sidebarOpen} closeSidebar={closeSidebar} />
        <main className="main-content">
          <div className="page-container">
            <Outlet />
          </div>
        </main>
      </div>
      <Footer />
    </div>
  )
}

export default Layout
