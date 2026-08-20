import fs from 'fs';
import path from 'path';

function getFiles(dir, filesList = []) {
    const files = fs.readdirSync(dir);
    for (const file of files) {
        const fullPath = path.join(dir, file);
        if (fs.statSync(fullPath).isDirectory()) {
            getFiles(fullPath, filesList);
        } else if (fullPath.endsWith('.blade.php')) {
            filesList.push(fullPath);
        }
    }
    return filesList;
}

const files = getFiles('resources/views');
let unsafeCount = 0;
let replacedCount = 0;

for (const file of files) {
    const content = fs.readFileSync(file, 'utf8');
    
    // Outer slot regex
    const outerRegex = /<x-slot:(actions|toolbar|filters|leading|trailing|content|footer|pagination)(?:[^>]*)>([\s\S]*?)<\/x-slot:\1>/gi;
    
    let outerMatch;
    let fileHasUnsafe = false;
    let newContent = content;
    
    while ((outerMatch = outerRegex.exec(content)) !== null) {
        const inner = outerMatch[2];
        const innerRegex = /<x-slot:(leading|trailing)>([\s\S]*?)<\/x-slot:\1>/gi;
        
        let innerMatch;
        let hasNestedSlot = false;
        while ((innerMatch = innerRegex.exec(inner)) !== null) {
            hasNestedSlot = true;
            console.log(`  -> Nested <x-slot:${innerMatch[1]}> found inside <x-slot:${outerMatch[1]}>`);
        }
        
        if (hasNestedSlot) {
            console.log(`Unsafe nested slot found in: ${file}`);
            fileHasUnsafe = true;
        }
    }
    
    if (fileHasUnsafe) {
        unsafeCount++;
        
        // Actually, let's fix it by replacing <x-slot:leading>...</x-slot:leading> with just its content,
        // but ONLY inside <x-ui.button ...> ... </x-ui.button> to be safe.
        // We do this replacement globally on the whole file to simplify, since we know it's unsafe in this file.
        // Even better, let's just do it everywhere on this file for <x-ui.button>
        
        const buttonRegex = /<x-ui\.button([\s\S]*?)>([\s\S]*?)<\/x-ui\.button>/gi;
        newContent = newContent.replace(buttonRegex, (btnMatch, btnAttrs, btnInner) => {
            let replacedBtnInner = btnInner;
            // Replace <x-slot:leading>...
            replacedBtnInner = replacedBtnInner.replace(/<x-slot:leading>([\s\S]*?)<\/x-slot:leading>/gi, '$1 ');
            // Replace <x-slot:trailing>...
            replacedBtnInner = replacedBtnInner.replace(/<x-slot:trailing>([\s\S]*?)<\/x-slot:trailing>/gi, ' $1');
            return `<x-ui.button${btnAttrs}>${replacedBtnInner}</x-ui.button>`;
        });
        
        if (content !== newContent) {
            fs.writeFileSync(file, newContent, 'utf8');
            console.log(`Fixed ${file}`);
            replacedCount++;
        }
    }
}

console.log(`\nTotal files with unsafe nested slots: ${unsafeCount}`);
console.log(`Total files fixed: ${replacedCount}`);
