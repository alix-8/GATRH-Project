# GATRH

[Dev Notes](https://docs.google.com/document/d/1YuV9XRYu1kzWDhISpTRxleVu-HYdjwQjBa8x7t6Ki6Q/edit?usp=sharing)

Branch Management
```

main
│
├── feature/attendance-parser
│      ├── parse raw text
│      ├── extract student names
│      ├── extract gathering info
│      ├── normalize values
│      └── validate records
│
├── feature/attendance-import
│      ├── import UI
│      ├── preview parsed records
│      └── confirmation
│
└── feature/attendance-db-insert
       ├── find student
       ├── duplicate checking
       └── insert attendance

```
