import os
import glob
import re

files = ['tests/feature/TeamMembersTabTest.php', 'tests/feature/InvitationEdgeCasesTest.php', 'tests/feature/InviteRoleTest.php']
for f in files:
    with open(f, 'r') as file:
        content = file.read()
    
    # find lines with 'email' => inside arrays passed to csrf
    # A safe way is to replace "    'email' =>" with "    'first_name' => 'Test', 'last_name' => 'User', 'email' =>"
    content = content.replace("                'email' =>", "                'first_name' => 'Test',\n                'last_name'  => 'User',\n                'email' =>")
    
    with open(f, 'w') as file:
        file.write(content)
print("done")
