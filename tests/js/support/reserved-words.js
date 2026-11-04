import fs from 'fs';
import path from 'path';

// The admin map resolves a reservedWords.json key to its sentence; a translate() mock must do the same
const reserved = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../reservedWords.json'), 'utf8'));

export function withReserved(translate) {
    return (text, ...args) => translate(Object.prototype.hasOwnProperty.call(reserved, text) ? reserved[text] : text, ...args);
}

export function reservedSentence(key) {
    return reserved[key];
}
