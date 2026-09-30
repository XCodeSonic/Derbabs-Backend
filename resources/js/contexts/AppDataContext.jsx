import React, { createContext, useContext, useEffect, useState } from 'react';
import { useAuth } from './AuthContext';
import { employeeAPI, departmentAPI, positionAPI, salaryGradeAPI } from '../services/api';

const AppDataContext = createContext(null);

export const useAppData = () => useContext(AppDataContext);

export const AppDataProvider = ({ children }) => {
  const { isAuthenticated, loading: authLoading } = useAuth();
  const [loading, setLoading] = useState(true);
  const [employees, setEmployees] = useState([]);
  const [departments, setDepartments] = useState([]);
  const [positions, setPositions] = useState([]);
  const [salaryGrades, setSalaryGrades] = useState([]);
  const [stats, setStats] = useState({});

  useEffect(() => {
    if (authLoading || !isAuthenticated) {
      setLoading(false);
      return;
    }

    const fetchAll = async () => {
      setLoading(true);
      try {
        const [emp, dep, pos, sg, stat] = await Promise.all([
          employeeAPI.getAll({ per_page: 1000 }),
          departmentAPI.getAll({ all: true }),
          positionAPI.getAll({ all: true }),
          salaryGradeAPI.getAll({ all: true }),
          employeeAPI.getStats()
        ]);

        setEmployees(emp.data?.data || []);
        setDepartments(dep.data?.data || []);
        setPositions(pos.data?.data || []);
        setSalaryGrades(sg.data?.data || []);
        setStats(stat.data || {});
      } catch (err) {
        console.error('App data fetch failed', err);
      } finally { setLoading(false); }
    };

    fetchAll();
  }, [authLoading, isAuthenticated]);

  return (
    <AppDataContext.Provider value={{
      loading,
      employees,
      departments,
      positions,
      salaryGrades,
      stats
    }}>
      {children}
    </AppDataContext.Provider>
  );
};