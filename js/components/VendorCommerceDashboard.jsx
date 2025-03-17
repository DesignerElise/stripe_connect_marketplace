import React, { useState, useEffect } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { AlertCircle, DollarSign, Package, ShoppingBag, ShoppingCart, Truck } from 'lucide-react';

const VendorCommerceDashboard = () => {
  const [storeData, setStoreData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [selectedPeriod, setSelectedPeriod] = useState('month');

  useEffect(() => {
    // This would normally fetch from an API endpoint
    // For demo purposes, we'll use sample data
    const fetchData = async () => {
      setLoading(true);
      
      try {
        // In a real implementation, this would be an API call
        // await fetch('/api/vendor-dashboard-data?period=' + selectedPeriod)
        
        // Sample data for demonstration
        const sampleData = {
          store: {
            name: "Sample Vendor Store",
            id: 1,
            productsCount: 24,
            ordersCount: 187,
            revenue: 12568.43
          },
          salesData: generateSampleSalesData(selectedPeriod),
          topProducts: [
            { name: "Product A", sold: 42, revenue: 2184.99 },
            { name: "Product B", sold: 38, revenue: 1899.50 },
            { name: "Product C", sold: 29, revenue: 1334.55 },
            { name: "Product D", sold: 27, revenue: 1079.73 },
            { name: "Product E", sold: 23, revenue: 914.25 }
          ],
          orderStatuses: [
            { status: "Pending", count: 12 },
            { status: "Processing", count: 8 },
            { status: "Completed", count: 152 },
            { status: "Cancelled", count: 15 }
          ]
        };
        
        setStoreData(sampleData);
      } catch (error) {
        console.error("Error fetching dashboard data:", error);
      } finally {
        setLoading(false);
      }
    };
    
    fetchData();
  }, [selectedPeriod]);

  // Helper function to generate sample sales data
  const generateSampleSalesData = (period) => {
    if (period === 'week') {
      return [
        { name: 'Mon', orders: 12, revenue: 689.99 },
        { name: 'Tue', orders: 19, revenue: 1023.45 },
        { name: 'Wed', orders: 15, revenue: 876.50 },
        { name: 'Thu', orders: 24, revenue: 1256.75 },
        { name: 'Fri', orders: 28, revenue: 1587.20 },
        { name: 'Sat', orders: 32, revenue: 1899.99 },
        { name: 'Sun', orders: 21, revenue: 1145.80 }
      ];
    } else if (period === 'month') {
      return [
        { name: 'Week 1', orders: 45, revenue: 2547.89 },
        { name: 'Week 2', orders: 52, revenue: 3102.45 },
        { name: 'Week 3', orders: 48, revenue: 2876.30 },
        { name: 'Week 4', orders: 42, revenue: 2541.79 }
      ];
    } else {
      return [
        { name: 'Jan', orders: 145, revenue: 8547.89 },
        { name: 'Feb', orders: 132, revenue: 7651.45 },
        { name: 'Mar', orders: 158, revenue: 9876.30 },
        { name: 'Apr', orders: 189, revenue: 11423.79 },
        { name: 'May', orders: 176, revenue: 10752.23 },
        { name: 'Jun', orders: 165, revenue: 9824.45 },
        { name: 'Jul', orders: 172, revenue: 10245.67 },
        { name: 'Aug', orders: 181, revenue: 11123.89 },
        { name: 'Sep', orders: 179, revenue: 10897.12 },
        { name: 'Oct', orders: 194, revenue: 12034.56 },
        { name: 'Nov', orders: 210, revenue: 13567.89 },
        { name: 'Dec', orders: 225, revenue: 15432.10 }
      ];
    }
  };

  if (loading) {
    return <div className="flex justify-center items-center h-64">Loading store data...</div>;
  }

  if (!storeData) {
    return (
      <div className="flex flex-col items-center justify-center h-64 text-center">
        <AlertCircle className="w-12 h-12 text-red-500 mb-4" />
        <h3 className="text-lg font-bold">Store Data Not Available</h3>
        <p className="text-gray-600 mt-2">Unable to load store dashboard data</p>
      </div>
    );
  }

  return (
    <div className="vendor-commerce-dashboard">
      {/* Store Overview */}
      <div className="store-overview bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 className="text-xl font-bold text-gray-800 mb-4">Store Overview: {storeData.store.name}</h2>
        
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="stat-card bg-blue-50 rounded-lg p-4 flex items-center">
            <div className="icon-wrapper bg-blue-100 p-3 rounded-full mr-4">
              <Package className="w-6 h-6 text-blue-600" />
            </div>
            <div>
              <div className="stat-value text-2xl font-bold text-blue-600">{storeData.store.productsCount}</div>
              <div className="stat-label text-sm text-gray-600">Products</div>
            </div>
          </div>
          
          <div className="stat-card bg-green-50 rounded-lg p-4 flex items-center">
            <div className="icon-wrapper bg-green-100 p-3 rounded-full mr-4">
              <ShoppingBag className="w-6 h-6 text-green-600" />
            </div>
            <div>
              <div className="stat-value text-2xl font-bold text-green-600">{storeData.store.ordersCount}</div>
              <div className="stat-label text-sm text-gray-600">Orders</div>
            </div>
          </div>
          
          <div className="stat-card bg-purple-50 rounded-lg p-4 flex items-center">
            <div className="icon-wrapper bg-purple-100 p-3 rounded-full mr-4">
              <DollarSign className="w-6 h-6 text-purple-600" />
            </div>
            <div>
              <div className="stat-value text-2xl font-bold text-purple-600">${storeData.store.revenue.toLocaleString()}</div>
              <div className="stat-label text-sm text-gray-600">Total Revenue</div>
            </div>
          </div>
        </div>
      </div>
      
      {/* Sales Chart */}
      <div className="sales-chart bg-white rounded-lg shadow-md p-6 mb-6">
        <div className="flex justify-between items-center mb-4">
          <h2 className="text-xl font-bold text-gray-800">Sales Performance</h2>
          
          <div className="period-selector flex space-x-2">
            <button 
              className={`px-3 py-1 rounded text-sm ${selectedPeriod === 'week' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'}`}
              onClick={() => setSelectedPeriod('week')}
            >
              Week
            </button>
            <button 
              className={`px-3 py-1 rounded text-sm ${selectedPeriod === 'month' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'}`}
              onClick={() => setSelectedPeriod('month')}
            >
              Month
            </button>
            <button 
              className={`px-3 py-1 rounded text-sm ${selectedPeriod === 'year' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'}`}
              onClick={() => setSelectedPeriod('year')}
            >
              Year
            </button>
          </div>
        </div>
        
        <div className="chart-container h-64">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart
              data={storeData.salesData}
              margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
            >
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="name" />
              <YAxis yAxisId="left" orientation="left" stroke="#8884d8" />
              <YAxis yAxisId="right" orientation="right" stroke="#82ca9d" />
              <Tooltip />
              <Legend />
              <Bar yAxisId="left" dataKey="orders" name="Orders" fill="#8884d8" />
              <Bar yAxisId="right" dataKey="revenue" name="Revenue ($)" fill="#82ca9d" />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>
      
      {/* Bottom Panels */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Top Products */}
        <div className="top-products bg-white rounded-lg shadow-md p-6">
          <h2 className="text-xl font-bold text-gray-800 mb-4">Top Selling Products</h2>
          
          <div className="overflow-x-auto">
            <table className="min-w-full">
              <thead>
                <tr className="bg-gray-100">
                  <th className="py-2 px-4 text-left">Product</th>
                  <th className="py-2 px-4 text-right">Units Sold</th>
                  <th className="py-2 px-4 text-right">Revenue</th>
                </tr>
              </thead>
              <tbody>
                {storeData.topProducts.map((product, index) => (
                  <tr key={index} className="border-b border-gray-200">
                    <td className="py-2 px-4">{product.name}</td>
                    <td className="py-2 px-4 text-right">{product.sold}</td>
                    <td className="py-2 px-4 text-right">${product.revenue.toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          
          <div className="mt-4 text-right">
            <a href="/product/add" className="text-blue-600 hover:text-blue-800 text-sm font-medium">
              + Add New Product
            </a>
          </div>
        </div>
        
        {/* Order Status */}
        <div className="order-status bg-white rounded-lg shadow-md p-6">
          <h2 className="text-xl font-bold text-gray-800 mb-4">Order Status</h2>
          
          <div className="status-cards grid grid-cols-2 gap-4">
            {storeData.orderStatuses.map((status, index) => (
              <div key={index} className="status-card border rounded-lg p-4">
                <div className="status-name text-gray-600 mb-1">{status.status}</div>
                <div className="status-count text-2xl font-bold">{status.count}</div>
              </div>
            ))}
          </div>
          
          <div className="mt-4 text-center">
            <a href="/orders" className="inline-block bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded">
              View All Orders
            </a>
          </div>
        </div>
      </div>
      
      {/* Store Settings Panel */}
      <div className="store-settings bg-white rounded-lg shadow-md p-6 mt-6">
        <h2 className="text-xl font-bold text-gray-800 mb-4">Store Configuration</h2>
        
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <a href={`/vendor/store/${storeData.store.id}/payment-gateways`} className="config-card border rounded-lg p-4 hover:bg-gray-50">
            <div className="flex items-center mb-2">
              <div className="icon-wrapper bg-indigo-100 p-2 rounded-full mr-2">
                <DollarSign className="w-5 h-5 text-indigo-600" />
              </div>
              <div className="card-title font-medium">Payment Methods</div>
            </div>
            <p className="text-sm text-gray-600">Configure how customers can pay for your products</p>
          </a>
          
          <a href={`/vendor/store/${storeData.store.id}/tax-settings`} className="config-card border rounded-lg p-4 hover:bg-gray-50">
            <div className="flex items-center mb-2">
              <div className="icon-wrapper bg-green-100 p-2 rounded-full mr-2">
                <DollarSign className="w-5 h-5 text-green-600" />
              </div>
              <div className="card-title font-medium">Tax Settings</div>
            </div>
            <p className="text-sm text-gray-600">Manage tax rates and configurations</p>
          </a>
          
          <a href={`/vendor/store/${storeData.store.id}/shipping-methods`} className="config-card border rounded-lg p-4 hover:bg-gray-50">
            <div className="flex items-center mb-2">
              <div className="icon-wrapper bg-blue-100 p-2 rounded-full mr-2">
                <Truck className="w-5 h-5 text-blue-600" />
              </div>
              <div className="card-title font-medium">Shipping Methods</div>
            </div>
            <p className="text-sm text-gray-600">Set up shipping options for your products</p>
          </a>
        </div>
      </div>
    </div>
  );
};

export default VendorCommerceDashboard;