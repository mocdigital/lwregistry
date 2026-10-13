#!/usr/bin/env node

import { readFileSync, writeFileSync, existsSync, copyFileSync } from 'fs'
import { loadEnv } from 'vite'

const templatePath = '.mcp.json.template'
const outputPath = '.mcp.json'
const backupPath = '.mcp.json.backup'

// Configuration version for template migrations
const CURRENT_TEMPLATE_VERSION = 1

/**
 * Deep merge two objects, with template taking precedence for known template servers
 * but preserving custom servers from existing config
 */
function mergeConfigurations(existing, template, templateServerNames) {
    const result = {
        _templateVersion: CURRENT_TEMPLATE_VERSION,
        mcpServers: {}
    }

    // First, add all template servers (these get updated)
    for (const [serverName, serverConfig] of Object.entries(template.mcpServers)) {
        result.mcpServers[serverName] = { ...serverConfig }
    }

    // Then, add custom servers from existing config (preserve these)
    if (existing?.mcpServers) {
        for (const [serverName, serverConfig] of Object.entries(existing.mcpServers)) {
            // Only add if this server is NOT in the template (custom server)
            if (!templateServerNames.includes(serverName)) {
                result.mcpServers[serverName] = { ...serverConfig }
                console.log(`🔧 Preserved custom server: ${serverName}`)
            }
        }
    }

    return result
}

/**
 * Replace environment variable placeholders in configuration
 */
function replaceEnvironmentVariables(config, env) {
    const configStr = JSON.stringify(config, null, 4)

    // Replace PROJECT_PATH with current working directory
    let result = configStr.replace(/PROJECT_PATH/g, () => {
        const projectPath = process.cwd()
        console.log(`✅ Replaced PROJECT_PATH with ${projectPath}`)
        return projectPath
    })

    // Replace VITE_ environment variables
    result = result.replace(/VITE_([A-Z0-9_]+)/g, (match, varName) => {
        const viteVar = `VITE_${varName}`
        const value = env[viteVar]

        if (!value) {
            console.warn(`⚠️  ${viteVar} not found in environment`)
            return match
        }

        console.log(`✅ Replaced ${viteVar}`)
        return value
    })

    return JSON.parse(result)
}

/**
 * Create backup of existing configuration (only if content will actually change)
 */
function createBackup() {
    if (existsSync(outputPath)) {
        copyFileSync(outputPath, backupPath)
        console.log(`💾 Created backup: ${backupPath}`)
        return true
    }
    return false
}

/**
 * Compare two configurations for semantic equality
 */
function configurationsAreEqual(config1, config2) {
    // Remove dynamic fields that change on every run
    const normalize = (config) => {
        if (!config) return null

        const normalized = { ...config }
        // Remove metadata fields that don't affect functionality
        delete normalized._metadata

        return normalized
    }

    const normalized1 = normalize(config1)
    const normalized2 = normalize(config2)

    // Deep comparison using JSON serialization
    return JSON.stringify(normalized1, Object.keys(normalized1 || {}).sort()) ===
           JSON.stringify(normalized2, Object.keys(normalized2 || {}).sort())
}

/**
 * Validate merged configuration
 */
function validateConfiguration(config) {
    if (!config.mcpServers || typeof config.mcpServers !== 'object') {
        throw new Error('Invalid configuration: mcpServers must be an object')
    }

    // Check each server has required properties
    for (const [serverName, serverConfig] of Object.entries(config.mcpServers)) {
        if (!serverConfig.command && !serverConfig.type) {
            throw new Error(`Invalid server ${serverName}: must have either 'command' or 'type' property`)
        }
    }

    console.log('✅ Configuration validation passed')
    return true
}

/**
 * Main merge function
 */
function mergeConfiguration() {
    console.log('🔄 Starting MCP configuration merge...')

    // Load environment variables
    const env = loadEnv('development', process.cwd(), '')

    // Check if template exists
    if (!existsSync(templatePath)) {
        console.error('❌ .mcp.json.template not found')
        process.exit(1)
    }

    try {
        // Load template
        console.log('📖 Loading template...')
        const templateRaw = readFileSync(templatePath, 'utf8')
        const template = JSON.parse(templateRaw)

        // Get list of template server names
        const templateServerNames = Object.keys(template.mcpServers || {})
        console.log(`📋 Template servers: ${templateServerNames.join(', ')}`)

        // Load existing configuration (if exists)
        let existing = null
        if (existsSync(outputPath)) {
            console.log('📖 Loading existing configuration...')
            const existingRaw = readFileSync(outputPath, 'utf8')
            existing = JSON.parse(existingRaw)

            // Show existing custom servers
            if (existing?.mcpServers) {
                const customServers = Object.keys(existing.mcpServers)
                    .filter(name => !templateServerNames.includes(name))
                if (customServers.length > 0) {
                    console.log(`🎯 Found custom servers: ${customServers.join(', ')}`)
                }
            }
        } else {
            console.log('ℹ️  No existing configuration found, creating new one')
        }

        // Replace environment variables in template
        console.log('🔄 Processing environment variables...')
        const processedTemplate = replaceEnvironmentVariables(template, env)

        // Merge configurations
        console.log('🔄 Merging configurations...')
        const mergedConfig = mergeConfigurations(existing, processedTemplate, templateServerNames)

        // Check if content actually changed
        const contentChanged = !configurationsAreEqual(existing, mergedConfig)

        if (!contentChanged) {
            console.log('✅ Configuration unchanged - skipping file write')
            console.log('📄 No changes to apply - files preserved')

            // Show summary without changes
            const totalServers = Object.keys(mergedConfig.mcpServers).length
            const customServers = Object.keys(mergedConfig.mcpServers)
                .filter(name => !templateServerNames.includes(name)).length
            console.log(`📊 Current: ${totalServers} total servers (${templateServerNames.length} template, ${customServers} custom)`)
            return
        }

        console.log('🔄 Configuration changes detected - writing files...')

        // Create backup only when making actual changes
        const hasBackup = createBackup()

        // Validate the merged configuration
        validateConfiguration(mergedConfig)

        // Write merged configuration
        writeFileSync(outputPath, JSON.stringify(mergedConfig, null, 4))

        console.log('✅ Successfully wrote MCP configuration changes')
        console.log(`📄 Updated: ${outputPath}`)

        if (hasBackup) {
            console.log(`💾 Backup available: ${backupPath}`)
        }

        // Show summary
        const totalServers = Object.keys(mergedConfig.mcpServers).length
        const customServers = Object.keys(mergedConfig.mcpServers)
            .filter(name => !templateServerNames.includes(name)).length

        console.log(`📊 Summary: ${totalServers} total servers (${templateServerNames.length} template, ${customServers} custom)`)

    } catch (error) {
        console.error('❌ Failed to merge MCP configuration:', error.message)

        // Restore backup if merge failed and backup exists
        if (existsSync(backupPath)) {
            console.log('🔄 Attempting to restore backup...')
            try {
                copyFileSync(backupPath, outputPath)
                console.log('✅ Backup restored successfully')
            } catch (restoreError) {
                console.error('❌ Failed to restore backup:', restoreError.message)
            }
        }

        process.exit(1)
    }
}

// Run if this script is executed directly
if (import.meta.url === `file://${process.argv[1]}`) {
    mergeConfiguration()
}

export { mergeConfiguration }