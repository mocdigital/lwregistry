#!/usr/bin/env node

import { readFileSync, writeFileSync, existsSync } from 'fs'
const outputPath = '.mcp.json'

/**
 * Validate server configuration based on type
 */
function validateServerConfig(serverName, config) {
    if (!serverName || typeof serverName !== 'string' || serverName.trim() === '') {
        throw new Error('Server name is required and must be a non-empty string')
    }

    if (!config || typeof config !== 'object') {
        throw new Error('Server configuration must be an object')
    }

    // Check for required properties based on server type
    if (config.type === 'http') {
        if (!config.url || typeof config.url !== 'string') {
            throw new Error('HTTP servers must have a valid URL')
        }
        if (!config.url.startsWith('http://') && !config.url.startsWith('https://')) {
            throw new Error('HTTP server URL must start with http:// or https://')
        }
    } else if (config.command) {
        if (!config.args || !Array.isArray(config.args)) {
            throw new Error('Command servers must have args as an array')
        }
    } else {
        throw new Error('Server must have either "type": "http" or "command" property')
    }

    console.log('✅ Server configuration validation passed')
}

/**
 * Load existing MCP configuration
 */
function loadExistingConfig() {
    if (!existsSync(outputPath)) {
        return {
            _templateVersion: 1,
            mcpServers: {}
        }
    }

    try {
        const configRaw = readFileSync(outputPath, 'utf8')
        return JSON.parse(configRaw)
    } catch (error) {
        throw new Error(`Failed to read existing configuration: ${error.message}`)
    }
}

/**
 * Interactive prompts for different server types
 */
function promptServerConfig() {
    console.log('\n🔧 MCP Server Configuration Wizard')
    console.log('This will help you add a custom MCP server safely.')
    console.log('\nSupported server types:')
    console.log('1. HTTP Server (API endpoint)')
    console.log('2. Command Server (local executable)')
    console.log('3. NPM Package Server')
    console.log('\n📋 Example configurations:')

    console.log(`
📘 HTTP Server Example:
{
    "my-api-server": {
        "type": "http",
        "url": "https://api.example.com/mcp",
        "headers": {
            "Authorization": "Bearer your-token-here"
        }
    }
}

📘 Command Server Example:
{
    "my-local-server": {
        "command": "python",
        "args": ["/path/to/your/script.py"],
        "env": {
            "CUSTOM_VAR": "value"
        }
    }
}

📘 NPM Package Server Example:
{
    "my-npm-server": {
        "command": "npx",
        "args": ["@company/mcp-package@latest", "ARG=value"]
    }
}`)

    console.log('\n⚠️  To add a server:')
    console.log('1. Create your server configuration JSON')
    console.log('2. Run: node scripts/add-mcp-server.js <server-name> <path-to-config.json>')
    console.log('3. Or edit this script to add interactive prompts')

    return null
}

/**
 * Add server to configuration
 */
function addServerToConfig(existing, serverName, serverConfig) {
    // Check for naming conflicts
    if (existing.mcpServers[serverName]) {
        const choice = process.env.FORCE_OVERWRITE === 'true'
        if (!choice) {
            throw new Error(`Server "${serverName}" already exists. Use FORCE_OVERWRITE=true to overwrite.`)
        }
        console.log(`⚠️  Overwriting existing server: ${serverName}`)
    }

    // Add the server
    const updated = {
        ...existing,
        mcpServers: {
            ...existing.mcpServers,
            [serverName]: serverConfig
        }
    }

    console.log(`✅ Added custom server: ${serverName}`)
    return updated
}

/**
 * Main function to add MCP server
 */
function addMcpServer(serverName, configPath) {
    console.log('🚀 Adding custom MCP server...')

    try {
        // Load server configuration
        let serverConfig
        if (configPath) {
            if (!existsSync(configPath)) {
                throw new Error(`Configuration file not found: ${configPath}`)
            }
            const configRaw = readFileSync(configPath, 'utf8')
            serverConfig = JSON.parse(configRaw)
            console.log(`📖 Loaded configuration from: ${configPath}`)
        } else {
            // Show examples and exit
            promptServerConfig()
            return
        }

        // Validate server configuration
        validateServerConfig(serverName, serverConfig)

        // Load existing configuration
        console.log('📖 Loading existing MCP configuration...')
        const existing = loadExistingConfig()

        // Create backup
        if (existsSync(outputPath)) {
            const timestamp = new Date().toISOString().replace(/[:.]/g, '-')
            const backupPath = `.mcp.json.backup.${timestamp}`
            writeFileSync(backupPath, JSON.stringify(existing, null, 4))
            console.log(`💾 Created backup: ${backupPath}`)
        }

        // Add server to configuration
        const updated = addServerToConfig(existing, serverName, serverConfig)

        // Write updated configuration
        writeFileSync(outputPath, JSON.stringify(updated, null, 4))
        console.log(`✅ Successfully added server "${serverName}" to MCP configuration`)
        console.log(`📄 Updated: ${outputPath}`)

        // Show summary
        const totalServers = Object.keys(updated.mcpServers).length
        console.log(`📊 Total MCP servers: ${totalServers}`)

        console.log('\n🎯 Next steps:')
        console.log('1. Restart your development server to use the new MCP server')
        console.log('2. The server will be preserved during template regeneration')
        console.log('3. Use npm run mcp:generate to update template-controlled servers')

    } catch (error) {
        console.error('❌ Failed to add MCP server:', error.message)
        process.exit(1)
    }
}

// Parse command line arguments
const args = process.argv.slice(2)
const serverName = args[0]
const configPath = args[1]

if (args.length === 0) {
    promptServerConfig()
} else if (args.length === 1 && (serverName === 'help' || serverName === '--help' || serverName === '-h')) {
    console.log(`
🔧 MCP Server Addition Tool

Usage:
    node scripts/add-mcp-server.js <server-name> <config-file.json>
    node scripts/add-mcp-server.js help                    # Show this help
    node scripts/add-mcp-server.js                         # Show examples

Examples:
    node scripts/add-mcp-server.js my-server config.json
    FORCE_OVERWRITE=true node scripts/add-mcp-server.js existing-server config.json

Environment Variables:
    FORCE_OVERWRITE=true     # Overwrite existing servers without prompt
`)
} else if (args.length >= 2) {
    addMcpServer(serverName, configPath)
} else {
    console.error('❌ Invalid arguments. Use --help for usage information.')
    process.exit(1)
}