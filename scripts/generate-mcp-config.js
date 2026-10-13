#!/usr/bin/env node

import { readFileSync, writeFileSync, existsSync } from 'fs'
import { loadEnv } from 'vite'

// Load environment variables the same way Vite does
const env = loadEnv('development', process.cwd(), '')

const templatePath = '.mcp.json.template'
const outputPath = '.mcp.json'

if (!existsSync(templatePath)) {
    console.error('❌ .mcp.json.template not found')
    process.exit(1)
}

try {
    // Read template
    let content = readFileSync(templatePath, 'utf8')

    // Replace VITE_ environment variables
    content = content.replace(/VITE_([A-Z0-9_]+)/g, (match, varName) => {
        const viteVar = `VITE_${varName}`
        const value = env[viteVar]

        if (!value) {
            console.warn(`⚠️  ${viteVar} not found in environment`)
            return match
        }

        return value
    })

    // Write output
    writeFileSync(outputPath, content)
    console.log('✅ Generated .mcp.json with environment variables')

} catch (error) {
    console.error('❌ Failed to generate .mcp.json:', error.message)
    process.exit(1)
}