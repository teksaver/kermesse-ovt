import sys

diff_file = sys.argv[1]
output_file = sys.argv[2]

with open(diff_file, 'r', encoding='utf-8') as f:
    lines = f.readlines()

out = []
current_file = None
skip = False

for line in lines:
    if line.startswith("diff --git"):
        parts = line.strip().split(" ")
        current_file = parts[-1]
        if "tests/" in current_file or line.startswith("diff --git a/tests/"):
            skip = True
        elif "deleted file mode" in "".join(lines[lines.index(line):lines.index(line)+5]):
            skip = True
        else:
            skip = False
    
    if not skip:
        out.append(line)

with open(output_file, 'w', encoding='utf-8') as f:
    f.writelines(out)
