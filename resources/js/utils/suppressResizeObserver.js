// src/utils/suppressResizeObserver.js
// This MUST be the first thing that runs

(function() {
  if (typeof window === 'undefined') return;
  
  // Store original functions
  const originalError = window.onerror;
  const originalConsoleError = console.error;
  const originalConsoleWarn = console.warn;
  
  // Function to check if error is ResizeObserver related
  const isResizeObserverError = (message) => {
    if (!message) return false;
    const msg = message.toString();
    return msg.includes('ResizeObserver') || 
           msg.includes('resize observer') ||
           msg === 'ResizeObserver loop completed with undelivered notifications.' ||
           msg === 'ResizeObserver loop limit exceeded';
  };
  
  // Override console.error
  console.error = function(...args) {
    const firstArg = args[0]?.toString?.() || '';
    if (isResizeObserverError(firstArg)) {
      return; // Silently suppress
    }
    return originalConsoleError.apply(this, args);
  };
  
  // Override console.warn
  console.warn = function(...args) {
    const firstArg = args[0]?.toString?.() || '';
    if (isResizeObserverError(firstArg)) {
      return; // Silently suppress
    }
    return originalConsoleWarn.apply(this, args);
  };
  
  // Override window error handler
  window.onerror = function(message, source, lineno, colno, error) {
    if (isResizeObserverError(message)) {
      return true; // Prevent default handling
    }
    if (originalError) {
      return originalError.apply(this, arguments);
    }
    return false;
  };
  
  // Add error event listener
  window.addEventListener('error', function(e) {
    if (isResizeObserverError(e.message)) {
      e.preventDefault();
      e.stopPropagation();
      return false;
    }
  }, true);
  
  // Handle unhandled promise rejections
  window.addEventListener('unhandledrejection', function(e) {
    const reason = e.reason?.message || e.reason || '';
    if (isResizeObserverError(reason)) {
      e.preventDefault();
      e.stopPropagation();
      return false;
    }
  });
  
  // Monkey patch ResizeObserver to prevent errors at source
  if (window.ResizeObserver) {
    const OriginalResizeObserver = window.ResizeObserver;
    window.ResizeObserver = class PatchedResizeObserver extends OriginalResizeObserver {
      constructor(callback) {
        const patchedCallback = (entries, observer) => {
          try {
            callback(entries, observer);
          } catch (error) {
            if (!isResizeObserverError(error?.message)) {
              throw error;
            }
          }
        };
        super(patchedCallback);
      }
    };
  }
})();