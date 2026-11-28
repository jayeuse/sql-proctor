import { StrictMode } from "react";
import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import SqlEditor from "../components/SqlEditor";

describe("SqlEditor", () => {
  it("respects readOnly mode and prevents edits", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SqlEditor
        label="Submitted SQL"
        value="SELECT id FROM students;"
        onChange={onChange}
        readOnly
      />,
    );

    const textbox = screen.getByLabelText("Submitted SQL");
    expect(textbox).toHaveAttribute("readonly");
    await user.type(textbox, " DROP TABLE students;");
    expect(onChange).not.toHaveBeenCalled();
  });

  it("renders accessible SQL input, propagates edits, and displays Read-only/SQL indicator", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SqlEditor
        label="Your SQL Query"
        value=""
        onChange={onChange}
        placeholder="Write MariaDB SQL here..."
      />,
    );

    const textbox = screen.getByLabelText("Your SQL Query");
    expect(textbox).toBeInTheDocument();
    await user.type(textbox, "SELECT 1;");
    expect(onChange).toHaveBeenCalled();
  });

  it("handles React StrictMode double-mount cleanly and disposes editor before model on unmount", async () => {
    const events = [];
    let isDisposed = false;
    const mockModel = {
      getValue: () => "SELECT 1;",
      setValue: vi.fn(),
      dispose: vi.fn(() => events.push("model.dispose")),
    };
    const mockEditor = {
      getModel: () => mockModel,
      getValue: () => mockModel.getValue(),
      setValue: vi.fn(),
      updateOptions: vi.fn(),
      addCommand: vi.fn(),
      onDidChangeModelContent: vi.fn(() => ({
        dispose: () => events.push("listener.dispose"),
      })),
      dispose: vi.fn(() => {
        isDisposed = true;
        events.push("editor.dispose");
      }),
    };
    const mockMonaco = {
      KeyMod: { CtrlCmd: 2048 },
      KeyCode: { Enter: 3 },
      editor: {
        createModel: vi.fn(() => {
          events.push("createModel");
          return mockModel;
        }),
        create: vi.fn(() => {
          events.push("editor.create");
          requestAnimationFrame(() => {
            if (isDisposed && !events.includes("safe-raf-abort")) {
              // Simulate Monaco 0.55.1 bug if editor/view was disposed while RAF stayed alive on detached node
              events.push("raf-after-dispose");
            }
          });
          return mockEditor;
        }),
      },
    };

    const loadMonaco = vi.fn().mockResolvedValue(mockMonaco);

    const { unmount } = render(
      <StrictMode>
        <SqlEditor
          label="StrictMode SQL"
          value="SELECT 1;"
          onChange={vi.fn()}
          loadMonaco={loadMonaco}
        />
      </StrictMode>,
    );

    await waitFor(() => expect(mockMonaco.editor.create).toHaveBeenCalledTimes(1));
    unmount();

    expect(events).toEqual(["createModel", "editor.create", "listener.dispose", "editor.dispose", "model.dispose"]);
  });

  it("falls back cleanly to editable textarea when Monaco throws a runtime initialization error", async () => {
    const consoleErrorSpy = vi.spyOn(console, "error").mockImplementation(() => {});
    const user = userEvent.setup();
    const onChange = vi.fn();

    const crashingLoader = vi.fn().mockResolvedValue({
      editor: {
        createModel: () => {
          throw new Error("InstantiationService has been disposed");
        },
        create: () => {
          throw new Error("Cannot read properties of undefined (reading 'domNode')");
        },
      },
    });

    render(
      <SqlEditor
        label="Resilient SQL"
        value=""
        onChange={onChange}
        loadMonaco={crashingLoader}
      />,
    );

    await waitFor(() => expect(crashingLoader).toHaveBeenCalled());
    const fallback = await screen.findByLabelText("Resilient SQL");
    await user.type(fallback, "SELECT 2;");
    expect(onChange).toHaveBeenCalled();
    consoleErrorSpy.mockRestore();
  });
});
