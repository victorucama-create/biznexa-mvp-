import React from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { FiBell, FiUser, FiMenu, FiSearch } from 'react-icons/fi'
import { useAuth } from '../../hooks/useAuth'
import './Header.css'

const Header = ({ toggleSidebar }) => {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = () => {
    logout()
    navigate('/login')
  }

  return (
    <header className="header">
      <div className="container">
        <div className="header-content">
          <div className="header-left">
            <button className="menu-btn" onClick={toggleSidebar}>
              <FiMenu size={24} />
            </button>
            <Link to="/dashboard" className="logo">
              <span className="logo-icon">B</span>
              <span className="logo-text">Biznexa</span>
            </Link>
          </div>

          <div className="header-center">
            <div className="search-bar">
              <FiSearch className="search-icon" />
              <input 
                type="text" 
                placeholder="Buscar produtos, vendas, clientes..." 
                className="search-input"
              />
            </div>
          </div>

          <div className="header-right">
            <button className="notification-btn">
              <FiBell size={20} />
              <span className="notification-badge">3</span>
            </button>
            
            <div className="user-dropdown">
              <button className="user-btn">
                <div className="user-avatar">
                  {user?.name?.charAt(0) || 'U'}
                </div>
                <span className="user-name">{user?.name || 'Usuário'}</span>
              </button>
              
              <div className="dropdown-menu">
                <Link to="/settings" className="dropdown-item">
                  <FiUser className="dropdown-icon" />
                  <span>Meu Perfil</span>
                </Link>
                <button onClick={handleLogout} className="dropdown-item logout">
                  <span>Sair</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>
  )
}

export default Header
