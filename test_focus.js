const fs = require('fs');

const style = fs.readFileSync('src/css/style.scss', 'utf8');
if (style.match(/&:focus-visible/g).length === 5) {
    console.log("Success: 5 focus-visible found in style.scss");
} else {
    console.log("Error: focus-visible missing or count mismatch");
    console.log("Count:", (style.match(/&:focus-visible/g) || []).length);
}
