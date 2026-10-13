#!/usr/bin/env node

import { existsSync, watch } from 'fs'
import { mergeConfiguration } from './merge-mcp-config.js'

const templatePath = '.mcp.json.template'

console.log('🔍 Starting MCP template watcher...')

if (!existsSync(templatePath)) {
    console.error('❌ .mcp.json.template not found')
    process.exit(1)
}

// Debounce timer to prevent rapid regenerations
let debounceTimer = null

function runMergeConfiguration() {
    try {
        console.log('🔄 Template changed, running smart merge...')
        mergeConfiguration()
        const timestamp = new Date().toLocaleTimeString()
        console.log(`✅ [${timestamp}] Smart merge completed successfully`)
    } catch (error) {
        console.error('❌ Failed to merge configuration:', error.message)
    }
}

// Skip initial merge since npm run mcp:merge already ran before this watcher started
console.log('⏭️  Skipping initial merge (already completed by npm run mcp:merge)')

// Watch for changes
const watcher = watch(templatePath, (eventType, filename) => {
    if (eventType === 'change') {
        console.log(`📝 Template file changed, running smart merge...`)

        // Clear existing debounce timer
        if (debounceTimer) {
            clearTimeout(debounceTimer)
        }

        // Debounce merge by 500ms
        debounceTimer = setTimeout(() => {
            runMergeConfiguration()
        }, 500)
    }
})

// Handle process termination gracefully
process.on('SIGINT', () => {
    console.log('\n👋 Stopping MCP template watcher...')
    watcher.close()
    process.exit(0)
})

console.log(`👀 Watching ${templatePath} for changes...`)
console.log('Press Ctrl+C to stop watching')