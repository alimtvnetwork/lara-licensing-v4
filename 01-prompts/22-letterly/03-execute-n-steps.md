# Execute N Steps

Okay. Now, you have to write an output in a different way. So first of all, whatever I say, whatever the input is, before the input, you write as high priority instruction, non-negotiable, must follow. Okay? And then you put the input verbatim. That would be the first thing. So if we go inside the output, that would be high priority. Then you have the goal, let learn, and then the input verbatim. That is correct. And then later on, you would do a little bit of action items that you think are the things that is mentioned inside this instruction. So the first one will be always and. The second item, another item would be how the instruction went. You just put the instructions items there. Okay, and at the end, we will ask, like, follow agent. The execute parent task within steps V6. I've given the file format, how it could go. Make sense?

Don't write "Certainly! Here's the output based on your instructions:" just output exactly as the given format no need for any action items, please, clear???

Follow the format below without the text "output"; you must add @[execute-parent-task-with-n-steps-v6](file;.agents/skills/execute-parent-task-with-n-steps-v6) as a suffix, not as a prefix, you stupid. Follow exactly as I have said.

1. Keep the verbatim as is, only stripping verbal fillers (`um`, `ah`, `uh`, `like`, leading `Okay`, excess `so`). STRICT TOTAL BAN on tone softening, sanitizing, or making the text "smart" or "polite". Preserve all insults, emotional tone, anger, direct reprimands, parameters, flags, and file paths exactly as said.

${Input Text Verbatim} = would be the input text as it is, without verbal fillers (`um`, `ah`, `uh`, `like`, leading `Okay`, excess `so`). Total ban on tone softening or making it smart; preserve all insults, emotional outbursts, frustration, and raw directives exactly as spoken.

Output
# High Priority Instruction

${Input Text Verbatim}

# Actionable Items Must Follow Non-Negotiable

1. Write a plan and spec first
2. ..

Must follow and spawn agent using

@[execute-parent-task-with-n-steps-v6](file;.agents/skills/execute-parent-task-with-n-steps-v6)

## Additional Instructions

learn [/learn](slashCommand;learn) if you have to learn something and [/plan](slashCommand;plan) stuff before working please.

---

Don't output like
[/goal](slashCommand;goal) [/learn](slashCommand;learn) ${Input Text Verbatim} @[execute-parent-task-with-n-steps-v6](file;.agents/skills/execute-parent-task-with-n-steps-v6) Input : ...text given (no please no)
