import os
import glob
import re

files = glob.glob('tests/feature/*Test.php') + glob.glob('tests/unit/*Test.php')

for f in files:
    with open(f, 'r') as file:
        content = file.read()
    
    new_content = re.sub(
        r"('phone'\s*=>\s*.*?,\s*)'admin_notes'\s*=>\s*'',?",
        r"\1",
        content
    )
        
    with open(f, 'w') as file:
        file.write(new_content)
print("done")
