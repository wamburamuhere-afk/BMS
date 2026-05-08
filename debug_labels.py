import re

file_path = r'c:\wamp64\www\bms\app\bms\Suppliers\suppliers.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Find all for attributes in labels
labels_for = re.findall(r'<label\s+[^>]*for=["\']([^"\']+)["\']', content)

# Find all ids
ids = re.findall(r'id=["\']([^"\']+)["\']', content)

print(f"Total labels with 'for': {len(labels_for)}")
print(f"Total IDs found: {len(ids)}")

unmatched = []
for f in labels_for:
    if f not in ids:
        unmatched.append(f)

if unmatched:
    print("Found labels with 'for' attributes that don't match any ID:")
    for u in unmatched:
        print(f" - {u}")
else:
    print("All label 'for' attributes match an ID.")

# Also check if any for matches a name but not an id
names = re.findall(r'name=["\']([^"\']+)["\']', content)
for f in labels_for:
    if f in names and f not in ids:
        print(f"Possible match by name only: {f}")
