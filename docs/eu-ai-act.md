# Essay Grader and the EU AI Act

How Essay Grader (`quiz_aigrader`) 5.3.0 lines up with the EU Artificial Intelligence Act,
Regulation (EU) 2024/1689, as amended by the Digital Omnibus on AI, Regulation (EU) 2026/1744.

Status: 25 November 2026. This is the provider's technical analysis, not legal advice. Each
organisation that uses Essay Grader in the EU should confirm its own position with its
advisers.

## 1. Is Essay Grader high-risk?

Annex III, point 3(b) lists AI systems "intended to be used to evaluate learning outcomes" in
education and vocational training as high-risk.

| How the site uses Essay Grader | Likely classification |
|---|---|
| Marks count toward a grade, a unit result, a qualification or progression | **High-risk** (Annex III, 3(b)) |
| Feedback only, with no effect on any recorded result (practice quizzes) | Not 3(b); transparency duties still apply |
| Grader adopts the suggestion with no real review | Still high-risk. Human review does not change the classification |

Points that settle it:

- The Commission's draft classification guidelines (19 May 2026) treat grading that counts
  toward a final evaluation as within 3(b), and say that keeping a human in the loop does not
  by itself take a system out of scope.
- The Article 6(3) exemption ("narrow procedural task", "preparatory task") is hard to rely
  on here: the suggestion is the substance of the evaluation, not a preparatory step.
- Profiling of natural persons always keeps a system high-risk. Essay Grader evaluates
  individual answers and does not build learner profiles; it should stay that way.

**Working position:** treat Essay Grader as a high-risk AI system whenever marks are recorded
against a qualification. That is the safe reading and the one this document plans for.

## 2. Dates

| Duty | Applies from |
|---|---|
| AI literacy (Article 4, softened by the Omnibus to "take measures") | Already applies |
| Transparency duties (Article 50) | 2 August 2026 |
| Machine-readable marking for systems already on the market (Article 50(2)) | 2 December 2026 |
| Annex III high-risk duties, providers and deployers | **2 December 2027** |

## 3. Who is responsible for what

**LMS Hosting Services (provider)** places Essay Grader on the market under its own name.
Provider duties (Articles 9 to 17, 43, 47 to 49), due by 2 December 2027:

- risk management system across the life of the product (Art 9)
- data governance for any data used to tune prompts or models (Art 10)
- technical documentation and logs (Arts 11, 12)
- instructions for use for deployers (Art 13; section 5 below is the basis)
- design for human oversight (Art 14), accuracy and robustness (Art 15)
- quality management system (Art 17)
- conformity assessment (internal control under Annex VI for 3(b)), EU declaration of
  conformity, CE marking, registration in the EU database (Arts 43, 47 to 49)
- post-market monitoring and serious-incident reporting (Arts 72, 73)

**The training organisation (deployer)** runs Essay Grader on its Moodle site. Deployer duties
(Article 26, 27, 86):

- use it in line with the instructions for use (26(1))
- assign competent, trained people to oversee it (26(2), Art 4)
- make sure the input (questions, criteria, documents) is relevant (26(4))
- monitor it, and suspend it and tell the provider when it presents a risk (26(5))
- keep the logs it generates for at least six months (26(6))
- tell learners that a high-risk AI system is used in decisions about them (26(11))
- give a clear explanation of the role the AI played on request (Art 86)
- public bodies and bodies providing public services: fundamental rights impact
  assessment before first use (Art 27)

The AI model supplier (the model behind the LMS Labs grading service) carries the
general-purpose AI model duties (Arts 53, 55), not the deployer.

## 4. What the plugin does for each duty

| Duty | Article | How Essay Grader supports it |
|---|---|---|
| Human oversight by design | 14 | Nothing reaches the gradebook until a grader approves it. The grader can change the mark and the feedback first |
| Guard against automation bias | 14(4)(b) | Minimum review time before Approve unlocks; acknowledgement step; review time logged per decision |
| Understand and interpret the output | 14(4)(c), 86 | Per-criterion breakdown against confirmed criteria; the grader sees why each mark was suggested |
| Input relevant to the purpose | 26(4) | Marking criteria check: criteria are proposed from the question and must be confirmed before they are used |
| Monitor and suspend | 26(5) | **New:** site-wide *Pause AI suggestions* setting. **New:** "Marks changed by graders" tile on the activity report, to spot rubber-stamping or drifting suggestions |
| Logs kept at least six months | 12, 26(6) | Every approval is stored in `quiz_aigrader_decisions`: AI mark and feedback, final mark and feedback, who approved, when, review time, criteria, model version, request ID. Kept until the site deletes it |
| Tell learners | 26(11), 50 | **New:** *Tell learners when AI drafted their feedback* (on by default) adds a line to feedback the AI drafted, in the learner's language, naming the approving grader |
| Machine-readable marking | 50(2) | The notice carries the class `quiz-aigrader-ai-notice`, and the decision record marks each result as AI-assisted |
| Explanation on request | 86 | The decision record and per-criterion feedback give the grader what they need to explain a result |
| AI literacy | 4 | **New:** "Using AI suggestions well" panel on the grading screen: limits, bias, responsibility and how to report problems |
| Accuracy and robustness | 15 | A service error, refused key, timeout or unreadable reply is shown as an error and never offered as a mark; suggested marks are clamped to the question's scale; "Grade all" stops on failures that would repeat and reports partial results honestly |
| Cybersecurity | 15(5) | API key stored encrypted; https only; redirects never followed; every reply from the service is validated and cleaned before use; approved feedback cleaned on the server before it reaches the learner |
| Privacy | GDPR | Moodle privacy API with complete metadata; learner names are not sent to the service; records removed when the quiz, attempt or user is deleted, with a daily clean-up for anything Moodle's events miss |
| Accessibility | 16(l) | No WCAG 2.2 AA violations (axe-core) on any screen in light or dark mode; fully keyboard-operable |

## 5. Instructions for use (basis for Article 13)

**Intended purpose.** Essay Grader drafts a mark and written feedback for essay responses in
Moodle quizzes, measured against the marking criteria a grader has confirmed for each question.
A qualified grader reviews every draft and decides the mark. It is not intended to mark
without human review, to rank or select learners, to detect cheating, or to profile learners.

**Known limits.**

- It can miss points, misread an answer or state things that are not in it.
- Accuracy can be lower for answers written in a second language, using local or workplace
  terms, or very short or very long answers.
- Its output is only as good as the confirmed criteria and reference documents.
- Accuracy is measured on each site: the activity report's **Suggestion accuracy** panel shows,
  for every learner language, how far the AI's suggested marks were from the marks graders
  approved, and in which direction. Quote those figures, for a period of at least a few hundred
  approvals, as the accuracy this site can expect.
- It does not see diagrams, images or attachments in the response.

**Human oversight.** Graders must read the learner's answer, not only the suggestion; change
the mark and feedback whenever they do not match; and approve only what they would have
marked themselves. Keep the minimum review time on. Review the "Marks changed by graders"
figure regularly: near 0% can mean approvals without checking, a high figure can mean the
criteria or the questions need attention.

**Suspend.** If suggestions look wrong, an administrator turns on *Pause AI suggestions*
(Site administration > Plugins > Activity modules > Quiz > Essay Grader). Graders keep marking
by hand. Report the problem to support@lmshostingservices.com.

**Logs.** Keep the decision records for at least six months, or longer if your assessment
records policy requires (for Australian RTOs, follow your records retention obligations).

**Learners.** Keep *Tell learners when AI drafted their feedback* on. Add a line to your
assessment information telling learners that AI assists marking and that a person decides
every result, and how to ask for an explanation or a review.

## 6. Provider work still to do before 2 December 2027

- Formal risk management file and quality management system
- Accuracy figures across languages and answer types, from the Suggestion accuracy panel on
  pilot sites, published in the instructions for use
- Technical documentation to Annex IV
- Internal-control conformity assessment, EU declaration of conformity, CE marking
- Registration in the EU database
- Post-market monitoring plan and incident reporting route
- Agreement with the model supplier on the GPAI information the provider needs (Art 53(1)(b))

## Sources

- Regulation (EU) 2024/1689 (Artificial Intelligence Act), EUR-Lex:
  https://eur-lex.europa.eu/eli/reg/2024/1689/oj
- Regulation (EU) 2026/1744 (Digital Omnibus on AI), Official Journal of the EU
- European Commission, draft guidelines on the classification of high-risk AI systems,
  19 May 2026
- European Commission, AI Act Service Desk: https://ai-act-service-desk.ec.europa.eu
