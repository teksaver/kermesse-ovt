import os
import glob
import re

files = glob.glob('tests/feature/*Test.php') + glob.glob('tests/unit/*Test.php')

for f in files:
    with open(f, 'r') as file:
        content = file.read()
    
    if "admin_notes" not in content and "db_signups" in content:
        new_content = re.sub(
            r"(phone\s+TEXT\s+NULL\s+DEFAULT\s+NULL,)",
            r"\1\n                admin_notes               TEXT     NULL DEFAULT NULL,",
            content
        )
        # Some tests might use different spacing, so another fallback regex:
        if new_content == content:
            new_content = re.sub(
                r"('phone'\s*=>\s*.*?,)",
                r"\1 'admin_notes' => '',",
                content
            )
            
        with open(f, 'w') as file:
            file.write(new_content)
print("done")
