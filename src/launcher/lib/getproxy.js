/**
 * Windows Proxy Configuration
 * Reads proxy settings from Windows registry and sets environment variables
 * Equivalent to getproxy.bat but in Node.js
 * 
 * Reference: https://github.com/python/cpython/blob/d86ab5dde286642a378fcc32c243bc3b4bed750d/Lib/urllib/request.py#L2684
 */

const { execFileSync } = require('child_process');
const path = require('path');

const SYSTEM32 = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32');
const REG_EXE  = path.join(SYSTEM32, 'reg.exe');
const SAFE_ENV = { ...process.env, PATH: SYSTEM32 };

/**
 * Get proxy settings from Windows registry
 * @returns {Object} Registry values or empty object if not available
 */
function readRegistryProxy() {
  try {
    const regPath = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings';
    
    // Check if reg query is available
    execFileSync(REG_EXE, ['query', regPath, '/v', 'ProxyEnable'], { stdio: 'pipe', env: SAFE_ENV, windowsHide: true });
    
    const regValues = {};
    
    // Get ProxyEnable
    try {
      const enableOutput = execFileSync(REG_EXE, ['query', regPath, '/v', 'ProxyEnable'], { encoding: 'utf-8', env: SAFE_ENV, windowsHide: true });
      const match = enableOutput.match(/ProxyEnable\s+REG_DWORD\s+0x([0-9a-f]+)/i);
      if (match) {
        regValues.proxyEnable = parseInt(match[1], 16);
      }
    } catch (e) {
      // Value not found
    }
    
    // Get ProxyServer
    try {
      const serverOutput = execFileSync(REG_EXE, ['query', regPath, '/v', 'ProxyServer'], { encoding: 'utf-8', env: SAFE_ENV, windowsHide: true });
      const match = serverOutput.match(/ProxyServer\s+REG_SZ\s+([^\r\n]+)/);
      if (match) {
        regValues.proxyServer = match[1].trim();
      }
    } catch (e) {
      // Value not found
    }
    
    // Get ProxyOverride
    try {
      const overrideOutput = execFileSync(REG_EXE, ['query', regPath, '/v', 'ProxyOverride'], { encoding: 'utf-8', env: SAFE_ENV, windowsHide: true });
      const match = overrideOutput.match(/ProxyOverride\s+REG_SZ\s+([^\r\n]+)/);
      if (match) {
        regValues.proxyOverride = match[1].trim();
      }
    } catch (e) {
      // Value not found
    }
    
    return regValues;
  } catch (e) {
    return {};
  }
}

/**
 * Configure proxy environment variables from Windows registry
 */
function configureProxy() {
  try {
    const regValues = readRegistryProxy();
    
    if (!regValues.proxyEnable || regValues.proxyEnable !== 1) {
      if (process.env.DEBUG_PROXY) {
        console.log('Proxy is disabled in Windows settings');
        console.log('Current proxy environment variables:');
        Object.keys(process.env).forEach(key => {
          if (key.toLowerCase().includes('proxy')) {
            console.log(`  ${key}=${process.env[key]}`);
          }
        });
      }
      return;
    }
    
    let proxyServer = regValues.proxyServer;
    if (!proxyServer) {
      if (process.env.DEBUG_PROXY) {
        console.log('No proxy server configured');
      }
      return;
    }
    
    // Check if ProxyServer matches the form <scheme>=<proxy>
    if (!proxyServer.includes('=')) {
      // Use one setting for all protocols
      proxyServer = `http=${proxyServer};https=${proxyServer};ftp=${proxyServer}`;
    }
    
    // Parse proxy settings
    const proxyEntries = proxyServer.split(';');
    const proxyVars = {};
    
    for (const entry of proxyEntries) {
      let [scheme, address] = entry.split('=');
      scheme = (scheme || 'http').trim().toLowerCase();
      address = (address || '').trim();
      
      if (!address) continue;
      
      // Add scheme:// prefix if missing
      if (!address.includes('://')) {
        if (scheme === 'http') address = `http://${address}`;
        else if (scheme === 'https') address = `https://${address}`;
        else if (scheme === 'ftp') address = `ftp://${address}`;
        else if (scheme === 'socks') address = `socks4://${address}`;
      }
      
      const proxyName = `${scheme}_proxy`;
      
      // Set proxy if not already set
      if (!process.env[proxyName]) {
        proxyVars[proxyName] = address;
        process.env[proxyName] = address;
      }
    }
    
    // Use SOCKS proxy for HTTP(S) if available
    if (process.env.socks_proxy) {
      if (!process.env.http_proxy) process.env.http_proxy = process.env.socks_proxy;
      if (!process.env.https_proxy) process.env.https_proxy = process.env.socks_proxy;
    }
    
    // Parse no_proxy configuration
    if (regValues.proxyOverride) {
      let noProxy = regValues.proxyOverride
        .split(';')
        .join(',')
        .replace(/<local>/g, 'localhost,127.0.0.0/8');
      
      process.env.no_proxy = noProxy;
      proxyVars.no_proxy = noProxy;
    }
    
    if (process.env.DEBUG_PROXY || Object.keys(proxyVars).length > 0) {
      console.log('Proxy Related Environment Variables:');
      Object.keys(process.env).forEach(key => {
        if (key.toLowerCase().includes('proxy')) {
          console.log(`  ${key}=${process.env[key]}`);
        }
      });
    }
  } catch (e) {
    if (process.env.DEBUG_PROXY) {
      console.error('Error reading proxy settings:', e.message);
    }
  }
}

module.exports = { configureProxy };
